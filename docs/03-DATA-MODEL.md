# 03 — Data Model

> **Status: Always active reference. Defines every entity in the system across all four phases. Phase 1 implements the columns marked `[P1]`, leaves the columns marked `[P2]`/`[P3]`/`[P4]` nullable or with safe defaults, and never restructures these tables in later phases — only adds to them.**

This is the **source of truth** for the database schema of Catalyst Engine. It defines the shape of every table the product will ever have, with phase-by-phase column additions explicitly marked. Cursor uses this document to write Phase 1 migrations that won't need to be undone.

---

## 1. Conventions used in this document

- `[P1]` — column built in Phase 1
- `[P2]`, `[P3]`, `[P4]` — column added in that phase. Phase 1 may include the column with a sensible default if the cost is low and benefit is real (avoiding future migrations on hot tables).
- `[design-only]` — table or column designed but not added until later phase. Phase 1 does NOT create it.
- All tables include `id` (bigint, primary key, auto-increment) and `ulid` (char(26), unique, indexed) unless noted.
- All user-facing API resources expose `ulid`, never `id`.
- All tenant-scoped tables include `agency_id` (foreign key to `agencies.id`, NOT NULL, indexed).
- All mutable tables include `created_at` and `updated_at` timestamps.
- Tables with soft delete include `deleted_at` (nullable timestamp, indexed).
- "Money columns" are stored as `bigint` representing **minor units** (pence, cents). Currency stored separately as `char(3)` ISO code.
- "JSON columns" are PostgreSQL `jsonb` for indexing and querying.
- "Timestamp columns" are stored in UTC with timezone (`timestamptz`).

---

## 2. Identity & authentication

### `users`

The base authentication entity. Every authenticatable principal is a User.

| Column                      | Type                  | Notes                                                             | Phase                    |
| --------------------------- | --------------------- | ----------------------------------------------------------------- | ------------------------ |
| `id`                        | bigint PK             |                                                                   | P1                       |
| `ulid`                      | char(26) unique       | Public identifier                                                 | P1                       |
| `email`                     | varchar(320) unique   | RFC 5321 max length                                               | P1                       |
| `email_verified_at`         | timestamptz null      |                                                                   | P1                       |
| `password`                  | varchar(255)          | Argon2id hash                                                     | P1                       |
| `type`                      | varchar(32)           | Enum: `creator`, `agency_user`, `brand_user`, `platform_admin`    | P1 (brand_user used P2+) |
| `name`                      | varchar(160)          | Display name                                                      | P1                       |
| `preferred_language`        | char(2)               | `en`, `pt`, `it`                                                  | P1                       |
| `preferred_currency`        | char(3)               | `EUR`, `GBP`, `USD` (display preference; campaigns set their own) | P1                       |
| `timezone`                  | varchar(64)           | IANA tz name                                                      | P1                       |
| `theme_preference`          | varchar(8)            | `light`, `dark`, `system`                                         | P1                       |
| `last_login_at`             | timestamptz null      |                                                                   | P1                       |
| `last_login_ip`             | inet null             |                                                                   | P1                       |
| `two_factor_secret`         | text null             | Encrypted                                                         | P1                       |
| `two_factor_recovery_codes` | text null             | Encrypted                                                         | P1                       |
| `two_factor_confirmed_at`   | timestamptz null      |                                                                   | P1                       |
| `mfa_required`              | boolean               | Forced 2FA (admins always true)                                   | P1                       |
| `is_suspended`              | boolean default false |                                                                   | P1                       |
| `suspended_reason`          | text null             |                                                                   | P1                       |
| `suspended_at`              | timestamptz null      |                                                                   | P1                       |
| `created_at`, `updated_at`  | timestamptz           |                                                                   | P1                       |
| `deleted_at`                | timestamptz null      | Soft delete                                                       | P1                       |

**Indexes:**

- `idx_users_email` on `email`
- `idx_users_type` on `type`
- `idx_users_deleted_at` on `deleted_at`

### `personal_access_tokens` (Sanctum default)

Standard Laravel Sanctum table. Used for API tokens (Phase 2 mobile, Phase 3 public API).

### `sessions` (Laravel default)

Standard Laravel sessions table for SPA auth.

### `password_reset_tokens` (Laravel default)

Standard.

---

## 3. Agencies (tenants)

### `agencies`

The top-level tenant.

| Column                     | Type                 | Notes                                                                        | Phase            |
| -------------------------- | -------------------- | ---------------------------------------------------------------------------- | ---------------- |
| `id`                       | bigint PK            |                                                                              | P1               |
| `ulid`                     | char(26) unique      |                                                                              | P1               |
| `name`                     | varchar(160)         |                                                                              | P1               |
| `slug`                     | varchar(64) unique   | URL-safe, agency-chosen                                                      | P1               |
| `country_code`             | char(2)              | ISO 3166-1 alpha-2                                                           | P1               |
| `default_currency`         | char(3)              | EUR/GBP usual                                                                | P1               |
| `default_language`         | char(2)              | en/pt/it                                                                     | P1               |
| `logo_path`                | varchar(512) null    | S3 path                                                                      | P1               |
| `primary_color`            | char(7) null         | Hex, optional white-label tint                                               | P1               |
| `subscription_tier`        | varchar(32)          | `pilot`, `starter`, `growth`, `enterprise`                                   | P1 (single tier) |
| `subscription_status`      | varchar(32)          | `active`, `paused`, `cancelled`, `trial`                                     | P1               |
| `billing_email`            | varchar(320) null    |                                                                              | P1               |
| `tax_id`                   | varchar(64) null     | VAT number                                                                   | P1               |
| `tax_id_country`           | char(2) null         |                                                                              | P1               |
| `address`                  | jsonb null           | Structured: line1, line2, city, region, postal, country                      | P1               |
| `settings`                 | jsonb                | Tenant-specific config (board defaults, blacklist notification policy, etc.) | P1               |
| `is_active`                | boolean default true |                                                                              | P1               |
| `created_at`, `updated_at` | timestamptz          |                                                                              | P1               |
| `deleted_at`               | timestamptz null     |                                                                              | P1               |

**Indexes:**

- `idx_agencies_slug` on `slug`
- `idx_agencies_subscription_status` on `subscription_status`

### `agency_users`

Pivot table for users belonging to an agency, with role.

| Column                     | Type             | Notes                                            | Phase |
| -------------------------- | ---------------- | ------------------------------------------------ | ----- |
| `id`                       | bigint PK        |                                                  | P1    |
| `agency_id`                | bigint FK        | `agencies.id`, RESTRICT                          | P1    |
| `user_id`                  | bigint FK        | `users.id`, RESTRICT                             | P1    |
| `role`                     | varchar(32)      | `agency_admin`, `agency_manager`, `agency_staff` | P1    |
| `invited_by_user_id`       | bigint FK null   | `users.id`, SET NULL                             | P1    |
| `invited_at`               | timestamptz null |                                                  | P1    |
| `accepted_at`              | timestamptz null |                                                  | P1    |
| `created_at`, `updated_at` | timestamptz      |                                                  | P1    |
| `deleted_at`               | timestamptz null |                                                  | P1    |

**Indexes:**

- `unique_agency_users_agency_user` on (`agency_id`, `user_id`) — a user can only have one role per agency
- `idx_agency_users_user_id` on `user_id`

---

## 4. Brands

### `brands`

A brand is owned by an agency. Brand is a first-class entity from Phase 1.

| Column                     | Type                  | Notes                                       | Phase                        |
| -------------------------- | --------------------- | ------------------------------------------- | ---------------------------- |
| `id`                       | bigint PK             |                                             | P1                           |
| `ulid`                     | char(26) unique       |                                             | P1                           |
| `agency_id`                | bigint FK             | `agencies.id`, RESTRICT                     | P1                           |
| `name`                     | varchar(160)          |                                             | P1                           |
| `slug`                     | varchar(64)           | Unique within agency                        | P1                           |
| `description`              | text null             |                                             | P1                           |
| `industry`                 | varchar(64) null      |                                             | P1                           |
| `website_url`              | varchar(2048) null    |                                             | P1                           |
| `logo_path`                | varchar(512) null     |                                             | P1                           |
| `default_currency`         | char(3)               |                                             | P1                           |
| `default_language`         | char(2)               |                                             | P1                           |
| `brand_safety_rules`       | jsonb null            | Topics to avoid, content guidelines         | P1                           |
| `exclusivity_window_days`  | integer null          | Default exclusivity window after a campaign | P2 (column nullable from P1) |
| `client_portal_enabled`    | boolean default false | Phase 2 feature flag at brand level         | P2 (column from P1)          |
| `created_at`, `updated_at` | timestamptz           |                                             | P1                           |
| `deleted_at`               | timestamptz null      |                                             | P1                           |

**Indexes:**

- `unique_brands_agency_slug` on (`agency_id`, `slug`)
- `idx_brands_agency_id` on `agency_id`

### `brand_users` `[design-only, P2]`

Pivot for brand-side users (clients of an agency). Phase 1 designs the shape; Phase 2 builds it.

```
id, brand_id, user_id, role (brand_admin, brand_user), invited_by_user_id,
invited_at, accepted_at, created_at, updated_at, deleted_at
```

Phase 1 does **not** create this table.

---

## 5. Creators (global entity)

### `creators`

A creator is a global entity, not tenant-scoped.

| Column                       | Type                  | Notes                                                           | Phase                        |
| ---------------------------- | --------------------- | --------------------------------------------------------------- | ---------------------------- |
| `id`                         | bigint PK             |                                                                 | P1                           |
| `ulid`                       | char(26) unique       |                                                                 | P1                           |
| `user_id`                    | bigint FK unique      | `users.id`, RESTRICT                                            | P1                           |
| `display_name`               | varchar(160)          |                                                                 | P1                           |
| `bio`                        | text null             |                                                                 | P1                           |
| `country_code`               | char(2)               |                                                                 | P1                           |
| `region`                     | varchar(160) null     | City/region, free text                                          | P1                           |
| `primary_language`           | char(2)               |                                                                 | P1                           |
| `secondary_languages`        | jsonb null            | Array of language codes                                         | P1                           |
| `avatar_path`                | varchar(512) null     |                                                                 | P1                           |
| `cover_path`                 | varchar(512) null     |                                                                 | P1                           |
| `categories`                 | jsonb                 | Array of category slugs (lifestyle, sports, etc.)               | P1                           |
| `verification_level`         | varchar(16)           | `unverified`, `email_verified`, `kyc_verified`, `tier_verified` | P1 (only first 3 used in P1) |
| `tier`                       | varchar(16)           | `standard`, `premium`, `top`                                    | P3 (column nullable from P1) |
| `application_status`         | varchar(16)           | `pending`, `approved`, `rejected`, `incomplete`                 | P1                           |
| `approved_at`                | timestamptz null      |                                                                 | P1                           |
| `approved_by_user_id`        | bigint FK null        | `users.id`, SET NULL — admin/agency staff who approved          | P1                           |
| `rejected_at`                | timestamptz null      |                                                                 | P1                           |
| `rejection_reason`           | text null             | Free text                                                       | P1                           |
| `profile_completeness_score` | smallint default 0    | 0-100 score, computed                                           | P1                           |
| `last_active_at`             | timestamptz null      |                                                                 | P1                           |
| `signed_master_contract_id`  | bigint FK null        | `contracts.id`                                                  | P1                           |
| `kyc_status`                 | varchar(16)           | `none`, `pending`, `verified`, `rejected`                       | P1                           |
| `kyc_verified_at`            | timestamptz null      |                                                                 | P1                           |
| `tax_profile_complete`       | boolean default false |                                                                 | P1                           |
| `payout_method_set`          | boolean default false |                                                                 | P1                           |
| `created_at`, `updated_at`   | timestamptz           |                                                                 | P1                           |
| `deleted_at`                 | timestamptz null      |                                                                 | P1                           |

**Indexes:**

- `idx_creators_user_id` on `user_id`
- `idx_creators_country_code` on `country_code`
- `idx_creators_application_status` on `application_status`
- `idx_creators_verification_level` on `verification_level`
- `idx_creators_categories_gin` on `categories` (GIN index for JSON containment queries)
- Postgres full-text index on `display_name`, `bio` (combined `tsvector` column)

### `creator_social_accounts`

Connected social media accounts. One row per (creator, platform) pair.

| Column                     | Type                  | Notes                                                                        | Phase               |
| -------------------------- | --------------------- | ---------------------------------------------------------------------------- | ------------------- |
| `id`                       | bigint PK             |                                                                              | P1                  |
| `ulid`                     | char(26) unique       |                                                                              | P1                  |
| `creator_id`               | bigint FK             | `creators.id`, CASCADE                                                       | P1                  |
| `platform`                 | varchar(16)           | `instagram`, `tiktok`, `youtube` (P1); `twitter`, `twitch`, `linkedin` (P2+) | P1                  |
| `platform_user_id`         | varchar(128)          | Platform's ID                                                                | P1                  |
| `handle`                   | varchar(128)          | @handle without @                                                            | P1                  |
| `profile_url`              | varchar(2048)         |                                                                              | P1                  |
| `oauth_access_token`       | text null             | Encrypted                                                                    | P1                  |
| `oauth_refresh_token`      | text null             | Encrypted                                                                    | P1                  |
| `oauth_expires_at`         | timestamptz null      |                                                                              | P1                  |
| `last_synced_at`           | timestamptz null      |                                                                              | P1                  |
| `sync_status`              | varchar(16)           | `pending`, `synced`, `failed`, `disconnected`                                | P1                  |
| `metrics`                  | jsonb null            | Latest cached metrics: followers, following, posts_count, engagement_rate    | P1                  |
| `audience_demographics`    | jsonb null            | Cached audience breakdown (P2 if API access)                                 | P2 (column from P1) |
| `is_primary`               | boolean default false | One primary platform per creator                                             | P1                  |
| `created_at`, `updated_at` | timestamptz           |                                                                              | P1                  |
| `deleted_at`               | timestamptz null      |                                                                              | P1                  |

**Indexes:**

- `unique_creator_social_creator_platform` on (`creator_id`, `platform`)
- `idx_creator_social_handle_platform` on (`platform`, `handle`)

### `creator_portfolio_items`

Video/image samples uploaded by the creator.

| Column                     | Type               | Notes                    | Phase |
| -------------------------- | ------------------ | ------------------------ | ----- |
| `id`                       | bigint PK          |                          | P1    |
| `ulid`                     | char(26) unique    |                          | P1    |
| `creator_id`               | bigint FK          | `creators.id`, CASCADE   | P1    |
| `kind`                     | varchar(16)        | `video`, `image`, `link` | P1    |
| `title`                    | varchar(255) null  |                          | P1    |
| `description`              | text null          |                          | P1    |
| `s3_path`                  | varchar(512) null  | For uploaded files       | P1    |
| `external_url`             | varchar(2048) null | For link items           | P1    |
| `thumbnail_path`           | varchar(512) null  |                          | P1    |
| `mime_type`                | varchar(64) null   |                          | P1    |
| `size_bytes`               | bigint null        |                          | P1    |
| `duration_seconds`         | integer null       | For videos               | P1    |
| `position`                 | integer            | Display order            | P1    |
| `created_at`, `updated_at` | timestamptz        |                          | P1    |
| `deleted_at`               | timestamptz null   |                          | P1    |

### `creator_availability_blocks`

Blocks of time when the creator is unavailable.

| Column                     | Type                  | Notes                                                                    | Phase               |
| -------------------------- | --------------------- | ------------------------------------------------------------------------ | ------------------- |
| `id`                       | bigint PK             |                                                                          | P1                  |
| `ulid`                     | char(26) unique       |                                                                          | P1                  |
| `creator_id`               | bigint FK             | `creators.id`, CASCADE                                                   | P1                  |
| `starts_at`                | timestamptz           |                                                                          | P1                  |
| `ends_at`                  | timestamptz           |                                                                          | P1                  |
| `is_all_day`               | boolean default false |                                                                          | P1                  |
| `kind`                     | varchar(16)           | `vacation`, `personal`, `exclusive_contract`, `assignment_auto`, `other` | P1                  |
| `block_type`               | varchar(8)            | `hard` (excluded entirely), `soft` (warning only)                        | P1                  |
| `reason`                   | varchar(255) null     | Free text, only visible to creator                                       | P1                  |
| `assignment_id`            | bigint FK null        | `campaign_assignments.id`, SET NULL — links auto-blocks to their cause   | P1                  |
| `is_recurring`             | boolean default false |                                                                          | P2 (column from P1) |
| `recurrence_rule`          | varchar(255) null     | RRULE if recurring                                                       | P2 (column from P1) |
| `external_calendar_id`     | varchar(255) null     | If synced from Google Calendar                                           | P2 (column from P1) |
| `external_event_id`        | varchar(255) null     |                                                                          | P2 (column from P1) |
| `created_at`, `updated_at` | timestamptz           |                                                                          | P1                  |

**Indexes:**

- `idx_availability_creator_dates` on (`creator_id`, `starts_at`, `ends_at`)
- `idx_availability_creator_kind` on (`creator_id`, `kind`)

### `creator_tax_profiles`

| Column                     | Type             | Notes                                                      | Phase |
| -------------------------- | ---------------- | ---------------------------------------------------------- | ----- |
| `id`                       | bigint PK        |                                                            | P1    |
| `creator_id`               | bigint FK unique | `creators.id`, CASCADE                                     | P1    |
| `legal_name`               | varchar(255)     | Encrypted                                                  | P1    |
| `tax_form_type`            | varchar(16)      | `eu_self_employed`, `eu_company`, `uk_self_employed`, etc. | P1    |
| `tax_id`                   | varchar(64)      | Encrypted (VAT number, NIF, partita IVA)                   | P1    |
| `tax_id_country`           | char(2)          |                                                            | P1    |
| `address`                  | jsonb            | Structured, encrypted                                      | P1    |
| `submitted_at`             | timestamptz null |                                                            | P1    |
| `verified_at`              | timestamptz null |                                                            | P1    |
| `verified_by_user_id`      | bigint FK null   | `users.id`, SET NULL                                       | P1    |
| `created_at`, `updated_at` | timestamptz      |                                                            | P1    |

### `creator_payout_methods`

| Column                     | Type                  | Notes                                           | Phase |
| -------------------------- | --------------------- | ----------------------------------------------- | ----- |
| `id`                       | bigint PK             |                                                 | P1    |
| `creator_id`               | bigint FK             | `creators.id`, CASCADE                          | P1    |
| `provider`                 | varchar(32)           | `stripe_connect`, `paypal` (P3+), `wise` (P3+)  | P1    |
| `provider_account_id`      | varchar(128)          | Stripe Connect account ID                       | P1    |
| `currency`                 | char(3)               |                                                 | P1    |
| `is_default`               | boolean default false |                                                 | P1    |
| `status`                   | varchar(16)           | `pending`, `verified`, `restricted`, `disabled` | P1    |
| `verified_at`              | timestamptz null      |                                                 | P1    |
| `created_at`, `updated_at` | timestamptz           |                                                 | P1    |

### `creator_kyc_verifications`

| Column                     | Type              | Notes                                               | Phase |
| -------------------------- | ----------------- | --------------------------------------------------- | ----- |
| `id`                       | bigint PK         |                                                     | P1    |
| `ulid`                     | char(26) unique   |                                                     | P1    |
| `creator_id`               | bigint FK         | `creators.id`, CASCADE                              | P1    |
| `provider`                 | varchar(32)       | `persona`, `veriff`, `onfido` (TBD)                 | P1    |
| `provider_session_id`      | varchar(255) null |                                                     | P1    |
| `provider_decision_id`     | varchar(255) null |                                                     | P1    |
| `status`                   | varchar(16)       | `started`, `pending`, `passed`, `failed`, `expired` | P1    |
| `decision_data`            | jsonb null        | Full provider response (encrypted)                  | P1    |
| `failure_reason`           | text null         |                                                     | P1    |
| `started_at`               | timestamptz null  |                                                     | P1    |
| `completed_at`             | timestamptz null  |                                                     | P1    |
| `expires_at`               | timestamptz null  |                                                     | P1    |
| `created_at`, `updated_at` | timestamptz       |                                                     | P1    |

---

## 6. Agency-Creator relationships

### `agency_creator_relations`

The per-agency view of a creator. Stores agency-specific data.

| Column                      | Type                  | Notes                                                                                    | Phase               |
| --------------------------- | --------------------- | ---------------------------------------------------------------------------------------- | ------------------- |
| `id`                        | bigint PK             |                                                                                          | P1                  |
| `ulid`                      | char(26) unique       |                                                                                          | P1                  |
| `agency_id`                 | bigint FK             | `agencies.id`, CASCADE                                                                   | P1                  |
| `creator_id`                | bigint FK             | `creators.id`, CASCADE                                                                   | P1                  |
| `relationship_status`       | varchar(16)           | `roster`, `external`, `prospect`                                                         | P1                  |
| `is_blacklisted`            | boolean default false |                                                                                          | P1                  |
| `blacklist_scope`           | varchar(8) null       | `agency` (entire agency) or `brand` (specific brand only — see brand_creator_blacklists) | P1                  |
| `blacklist_reason`          | text null             | Mandatory if blacklisted                                                                 | P1                  |
| `blacklist_type`            | varchar(8) null       | `hard`, `soft`                                                                           | P1                  |
| `blacklisted_at`            | timestamptz null      |                                                                                          | P1                  |
| `blacklisted_by_user_id`    | bigint FK null        |                                                                                          | P1                  |
| `notification_sent_at`      | timestamptz null      | If creator was notified                                                                  | P1                  |
| `appeal_status`             | varchar(16) null      | `none`, `pending`, `approved`, `rejected`                                                | P2 (column from P1) |
| `appeal_submitted_at`       | timestamptz null      |                                                                                          | P2 (column from P1) |
| `internal_rating`           | smallint null         | 1-5 stars from agency's POV                                                              | P1                  |
| `internal_notes`            | text null             |                                                                                          | P1                  |
| `total_campaigns_completed` | integer default 0     | Denormalized counter                                                                     | P1                  |
| `total_paid_minor_units`    | bigint default 0      | Denormalized                                                                             | P1                  |
| `last_engaged_at`           | timestamptz null      |                                                                                          | P1                  |
| `created_at`, `updated_at`  | timestamptz           |                                                                                          | P1                  |

**Indexes:**

- `unique_agency_creator` on (`agency_id`, `creator_id`)
- `idx_agency_creator_blacklisted` on (`agency_id`, `is_blacklisted`)

### `brand_creator_blacklists`

Brand-scoped blacklists (when a creator is blocked from a specific brand but ok for others in the agency).

| Column                     | Type             | Notes                  | Phase |
| -------------------------- | ---------------- | ---------------------- | ----- |
| `id`                       | bigint PK        |                        | P1    |
| `brand_id`                 | bigint FK        | `brands.id`, CASCADE   | P1    |
| `creator_id`               | bigint FK        | `creators.id`, CASCADE | P1    |
| `reason`                   | text             |                        | P1    |
| `block_type`               | varchar(8)       | `hard`, `soft`         | P1    |
| `created_by_user_id`       | bigint FK        | `users.id`, SET NULL   | P1    |
| `notification_sent_at`     | timestamptz null |                        | P1    |
| `created_at`, `updated_at` | timestamptz      |                        | P1    |

**Indexes:**

- `unique_brand_creator_blacklist` on (`brand_id`, `creator_id`)

---

## 7. Campaigns

### `campaigns`

| Column                           | Type                  | Notes                                                                                     | Phase                                    |
| -------------------------------- | --------------------- | ----------------------------------------------------------------------------------------- | ---------------------------------------- |
| `id`                             | bigint PK             |                                                                                           | P1                                       |
| `ulid`                           | char(26) unique       |                                                                                           | P1                                       |
| `agency_id`                      | bigint FK             | `agencies.id`, RESTRICT                                                                   | P1                                       |
| `brand_id`                       | bigint FK             | `brands.id`, RESTRICT                                                                     | P1                                       |
| `name`                           | varchar(255)          |                                                                                           | P1                                       |
| `description`                    | text null             |                                                                                           | P1                                       |
| `objective`                      | varchar(32)           | `awareness`, `engagement`, `conversion`, `ugc`, `launch`                                  | P1                                       |
| `status`                         | varchar(16)           | `draft`, `active`, `paused`, `completed`, `cancelled`                                     | P1                                       |
| `budget_minor_units`             | bigint                | Total budget                                                                              | P1                                       |
| `budget_currency`                | char(3)               |                                                                                           | P1                                       |
| `starts_at`                      | timestamptz null      | Campaign start                                                                            | P1                                       |
| `ends_at`                        | timestamptz null      | Campaign end                                                                              | P1                                       |
| `posting_window_starts_at`       | timestamptz null      | When creators must post                                                                   | P1                                       |
| `posting_window_ends_at`         | timestamptz null      |                                                                                           | P1                                       |
| `brief`                          | jsonb                 | Structured: deliverables, do/don'ts, hashtags, mentions, links, usage_rights, attachments | P1                                       |
| `target_creator_count`           | integer null          | How many creators are wanted                                                              | P1                                       |
| `created_by_user_id`             | bigint FK             | `users.id`, RESTRICT                                                                      | P1                                       |
| `published_at`                   | timestamptz null      | When campaign went from draft to active                                                   | P1                                       |
| `completed_at`                   | timestamptz null      |                                                                                           | P1                                       |
| `is_marketplace_visible`         | boolean default false | If creators can see and apply (P3 marketplace)                                            | P3 (column from P1)                      |
| `marketplace_open_at`            | timestamptz null      |                                                                                           | P3 (column from P1)                      |
| `marketplace_close_at`           | timestamptz null      |                                                                                           | P3 (column from P1)                      |
| `requires_per_campaign_contract` | boolean default false | Whether to add an addendum on top of master                                               | P1 (column from P1, used P2 for full UI) |
| `created_at`, `updated_at`       | timestamptz           |                                                                                           | P1                                       |
| `deleted_at`                     | timestamptz null      |                                                                                           | P1                                       |

**Indexes:**

- `idx_campaigns_agency_brand` on (`agency_id`, `brand_id`)
- `idx_campaigns_status` on `status`
- `idx_campaigns_dates` on (`starts_at`, `ends_at`)

### `campaign_assignments`

The heart of the system. One assignment = one creator engaged on one campaign.

| Column                               | Type             | Notes                                              | Phase |
| ------------------------------------ | ---------------- | -------------------------------------------------- | ----- |
| `id`                                 | bigint PK        |                                                    | P1    |
| `ulid`                               | char(26) unique  |                                                    | P1    |
| `agency_id`                          | bigint FK        | `agencies.id`, RESTRICT (denormalized for tenancy) | P1    |
| `campaign_id`                        | bigint FK        | `campaigns.id`, CASCADE                            | P1    |
| `brand_id`                           | bigint FK        | `brands.id`, RESTRICT (denormalized)               | P1    |
| `creator_id`                         | bigint FK        | `creators.id`, RESTRICT                            | P1    |
| `status`                             | varchar(32)      | See state machine below                            | P1    |
| `invited_at`                         | timestamptz null |                                                    | P1    |
| `invited_by_user_id`                 | bigint FK null   |                                                    | P1    |
| `responded_at`                       | timestamptz null | When creator accepted/declined/countered           | P1    |
| `accepted_at`                        | timestamptz null |                                                    | P1    |
| `contract_id`                        | bigint FK null   | `contracts.id` if a per-campaign addendum          | P1    |
| `agreed_fee_minor_units`             | bigint null      | Final agreed fee                                   | P1    |
| `agreed_fee_currency`                | char(3) null     |                                                    | P1    |
| `markup_minor_units`                 | bigint null      | Hidden from brand (margin agency adds)             | P1    |
| `total_charged_to_brand_minor_units` | bigint null      | What the brand sees / pays                         | P1    |
| `deliverables`                       | jsonb            | Specific list (overrides campaign brief if set)    | P1    |
| `posting_due_at`                     | timestamptz null |                                                    | P1    |
| `submitted_draft_at`                 | timestamptz null |                                                    | P1    |
| `approved_at`                        | timestamptz null |                                                    | P1    |
| `posted_at`                          | timestamptz null |                                                    | P1    |
| `verified_live_at`                   | timestamptz null | When social API confirmed post is live             | P1    |
| `payment_id`                         | bigint FK null   | `payments.id`                                      | P1    |
| `cancelled_at`                       | timestamptz null |                                                    | P1    |
| `cancelled_reason`                   | text null        |                                                    | P1    |
| `cancelled_by_user_id`               | bigint FK null   |                                                    | P1    |
| `notes`                              | text null        | Internal agency notes                              | P1    |
| `created_at`, `updated_at`           | timestamptz      |                                                    | P1    |
| `deleted_at`                         | timestamptz null |                                                    | P1    |

**State machine for `status`:**

```
invited
  ↓
responded (split into):
  → declined  (terminal)
  → countered (negotiation)
  → accepted
       ↓
   contracted   (master signed; addendum signed if required)
       ↓
   producing
       ↓
   draft_submitted
       ↓ (review)
       → revision_requested → producing (loop)
       → approved
            ↓
        posted          (creator says posted; awaiting verification)
            ↓
        live_verified   (system verified via API)
            ↓
        payment_held    (escrow holds — auto)
            ↓
        payment_released (funds out — terminal success)

Cancellation can happen from any non-terminal state → cancelled (terminal)
```

State transitions are managed by `CampaignAssignmentStateMachine` service. Every transition is logged in `audit_logs` and may trigger card movement on the board.

**Indexes:**

- `unique_assignment_campaign_creator` on (`campaign_id`, `creator_id`) — one assignment per pair
- `idx_assignments_agency_status` on (`agency_id`, `status`)
- `idx_assignments_creator_status` on (`creator_id`, `status`)
- `idx_assignments_brand_id` on `brand_id`
- `idx_assignments_dates` on `posting_due_at`

### `campaign_drafts`

Draft submissions for review.

| Column                       | Type             | Notes                                                                 | Phase               |
| ---------------------------- | ---------------- | --------------------------------------------------------------------- | ------------------- |
| `id`                         | bigint PK        |                                                                       | P1                  |
| `ulid`                       | char(26) unique  |                                                                       | P1                  |
| `assignment_id`              | bigint FK        | `campaign_assignments.id`, CASCADE                                    | P1                  |
| `version`                    | integer          | Increments per resubmission                                           | P1                  |
| `submitted_by_creator_id`    | bigint FK        |                                                                       | P1                  |
| `submitted_at`               | timestamptz      |                                                                       | P1                  |
| `caption`                    | text null        |                                                                       | P1                  |
| `hashtags`                   | jsonb null       | Array                                                                 | P1                  |
| `mentions`                   | jsonb null       |                                                                       | P1                  |
| `media_attachments`          | jsonb            | Array of {s3_path, mime_type, kind, thumbnail_path, duration_seconds} | P1                  |
| `review_status`              | varchar(16)      | `pending`, `approved`, `rejected`, `revision_requested`               | P1                  |
| `reviewed_at`                | timestamptz null |                                                                       | P1                  |
| `reviewed_by_user_id`        | bigint FK null   |                                                                       | P1                  |
| `review_feedback`            | text null        |                                                                       | P1                  |
| `client_review_status`       | varchar(16) null | `pending`, `approved`, `rejected` (P2 brand portal)                   | P2 (column from P1) |
| `client_reviewed_at`         | timestamptz null |                                                                       | P2 (column from P1) |
| `client_reviewed_by_user_id` | bigint FK null   |                                                                       | P2 (column from P1) |
| `client_review_feedback`     | text null        |                                                                       | P2 (column from P1) |
| `ai_qc_results`              | jsonb null       | Phase 3 automated checks                                              | P3 (column from P1) |
| `ai_qc_passed`               | boolean null     |                                                                       | P3 (column from P1) |
| `created_at`, `updated_at`   | timestamptz      |                                                                       | P1                  |

### `campaign_posted_content`

Tracks the actual published post on social.

| Column                     | Type              | Notes                                                     | Phase               |
| -------------------------- | ----------------- | --------------------------------------------------------- | ------------------- |
| `id`                       | bigint PK         |                                                           | P1                  |
| `ulid`                     | char(26) unique   |                                                           | P1                  |
| `assignment_id`            | bigint FK         | `campaign_assignments.id`, CASCADE                        | P1                  |
| `platform`                 | varchar(16)       |                                                           | P1                  |
| `post_url`                 | varchar(2048)     |                                                           | P1                  |
| `platform_post_id`         | varchar(128) null |                                                           | P1                  |
| `posted_at`                | timestamptz null  |                                                           | P1                  |
| `verified_at`              | timestamptz null  |                                                           | P1                  |
| `verification_status`      | varchar(16)       | `pending`, `verified`, `not_found`, `mismatch`            | P1                  |
| `last_metrics_synced_at`   | timestamptz null  |                                                           | P1                  |
| `metrics`                  | jsonb null        | likes, comments, shares, saves, views, reach, impressions | P1                  |
| `metrics_history`          | jsonb null        | Time-series snapshots                                     | P2 (column from P1) |
| `created_at`, `updated_at` | timestamptz       |                                                           | P1                  |

---

## 8. Contracts

### `contracts`

Polymorphic — attaches to a Creator (master) or a CampaignAssignment (addendum).

| Column                     | Type              | Notes                                                          | Phase |
| -------------------------- | ----------------- | -------------------------------------------------------------- | ----- |
| `id`                       | bigint PK         |                                                                | P1    |
| `ulid`                     | char(26) unique   |                                                                | P1    |
| `agency_id`                | bigint FK null    | `agencies.id`, RESTRICT — null for global Catalyst Engine T&Cs | P1    |
| `kind`                     | varchar(16)       | `master_universal`, `master_agency`, `per_campaign`            | P1    |
| `subject_type`             | varchar(64)       | `creator` or `campaign_assignment`                             | P1    |
| `subject_id`               | bigint            | Polymorphic FK                                                 | P1    |
| `template_id`              | bigint FK null    | `contract_templates.id`                                        | P1    |
| `version`                  | integer           |                                                                | P1    |
| `title`                    | varchar(255)      |                                                                | P1    |
| `body_markdown`            | text              | The actual contract text                                       | P1    |
| `body_pdf_path`            | varchar(512) null | S3 path to rendered PDF                                        | P1    |
| `signature_provider`       | varchar(32) null  | `docusign`, `dropboxsign`, `internal`                          | P1    |
| `signature_envelope_id`    | varchar(255) null | Provider's reference                                           | P1    |
| `status`                   | varchar(16)       | `draft`, `sent`, `signed`, `declined`, `expired`, `superseded` | P1    |
| `sent_at`                  | timestamptz null  |                                                                | P1    |
| `signed_at`                | timestamptz null  |                                                                | P1    |
| `signed_by_creator_id`     | bigint FK null    |                                                                | P1    |
| `signed_signature_data`    | jsonb null        | IP, user agent, timestamp from provider                        | P1    |
| `expires_at`               | timestamptz null  |                                                                | P1    |
| `created_by_user_id`       | bigint FK null    |                                                                | P1    |
| `created_at`, `updated_at` | timestamptz       |                                                                | P1    |
| `deleted_at`               | timestamptz null  |                                                                | P1    |

**Indexes:**

- `idx_contracts_subject` on (`subject_type`, `subject_id`)
- `idx_contracts_status` on `status`

### `contract_templates`

Reusable contract templates per agency.

| Column                     | Type                 | Notes                                               | Phase |
| -------------------------- | -------------------- | --------------------------------------------------- | ----- |
| `id`                       | bigint PK            |                                                     | P1    |
| `ulid`                     | char(26) unique      |                                                     | P1    |
| `agency_id`                | bigint FK null       | `agencies.id`, RESTRICT — null for global default   | P1    |
| `kind`                     | varchar(16)          | `master_universal`, `master_agency`, `per_campaign` | P1    |
| `name`                     | varchar(160)         |                                                     | P1    |
| `body_markdown`            | text                 | With placeholders like `{{creator.legal_name}}`     | P1    |
| `version`                  | integer              |                                                     | P1    |
| `is_active`                | boolean default true |                                                     | P1    |
| `created_by_user_id`       | bigint FK null       |                                                     | P1    |
| `created_at`, `updated_at` | timestamptz          |                                                     | P1    |
| `deleted_at`               | timestamptz null     |                                                     | P1    |

---

## 9. Payments

### `payments`

One payment per CampaignAssignment (typically). Escrow lifecycle.

| Column                              | Type              | Notes                                                           | Phase                                  |
| ----------------------------------- | ----------------- | --------------------------------------------------------------- | -------------------------------------- |
| `id`                                | bigint PK         |                                                                 | P1                                     |
| `ulid`                              | char(26) unique   |                                                                 | P1                                     |
| `agency_id`                         | bigint FK         | `agencies.id`, RESTRICT                                         | P1                                     |
| `assignment_id`                     | bigint FK unique  | `campaign_assignments.id`, RESTRICT                             | P1                                     |
| `brand_charge_minor_units`          | bigint            | What the brand pays the agency                                  | P1                                     |
| `creator_payout_minor_units`        | bigint            | What the creator receives (net of platform fees)                | P1                                     |
| `platform_fee_minor_units`          | bigint default 0  | Catalyst Engine's cut                                           | P1                                     |
| `agency_markup_minor_units`         | bigint default 0  | Hidden from brand                                               | P1                                     |
| `currency`                          | char(3)           |                                                                 | P1                                     |
| `escrow_status`                     | varchar(16)       | `pending_funding`, `funded`, `released`, `refunded`, `disputed` | P1                                     |
| `funded_at`                         | timestamptz null  | When agency/brand funded the escrow                             | P1                                     |
| `funded_via_provider_charge_id`     | varchar(255) null | Stripe charge ID                                                | P1                                     |
| `released_at`                       | timestamptz null  | When creator was paid                                           | P1                                     |
| `released_via_provider_transfer_id` | varchar(255) null | Stripe transfer ID                                              | P1                                     |
| `payout_speed`                      | varchar(16)       | `standard`, `fast_48h`, `instant`                               | P2 (column default 'standard' from P1) |
| `payout_speed_fee_minor_units`      | bigint default 0  |                                                                 | P2 (column from P1)                    |
| `dispute_status`                    | varchar(16) null  | `open`, `resolved_creator`, `resolved_brand`, `closed`          | P1                                     |
| `dispute_opened_at`                 | timestamptz null  |                                                                 | P1                                     |
| `created_at`, `updated_at`          | timestamptz       |                                                                 | P1                                     |

### `payment_events`

Append-only event log for payment state changes.

| Column              | Type              | Notes                                                                                                 | Phase |
| ------------------- | ----------------- | ----------------------------------------------------------------------------------------------------- | ----- |
| `id`                | bigint PK         |                                                                                                       | P1    |
| `ulid`              | char(26) unique   |                                                                                                       | P1    |
| `payment_id`        | bigint FK         | `payments.id`, RESTRICT                                                                               | P1    |
| `event_type`        | varchar(64)       | `funded`, `released`, `refunded`, `disputed`, `dispute_resolved`, `payout_initiated`, `payout_failed` | P1    |
| `event_data`        | jsonb             | Provider-specific payload                                                                             | P1    |
| `provider`          | varchar(32)       |                                                                                                       | P1    |
| `provider_event_id` | varchar(255) null | For idempotency                                                                                       | P1    |
| `created_at`        | timestamptz       |                                                                                                       | P1    |

**Indexes:**

- `unique_payment_events_provider_event_id` on (`provider`, `provider_event_id`) where not null
- `idx_payment_events_payment_id` on `payment_id`

### `payment_invoices` `[design-only, P2]`

Agency-level invoicing rolling up multiple payments per brand. Phase 1 designs the shape; Phase 2 builds.

---

## 10. Boards & automation

See `10-BOARD-AUTOMATION.md` for full spec. Tables here.

### `boards`

One board per campaign.

| Column                     | Type             | Notes                   | Phase |
| -------------------------- | ---------------- | ----------------------- | ----- |
| `id`                       | bigint PK        |                         | P1    |
| `ulid`                     | char(26) unique  |                         | P1    |
| `agency_id`                | bigint FK        | RESTRICT (denormalized) | P1    |
| `campaign_id`              | bigint FK unique | `campaigns.id`, CASCADE | P1    |
| `created_at`, `updated_at` | timestamptz      |                         | P1    |

### `board_columns`

| Column                     | Type                  | Notes                           | Phase |
| -------------------------- | --------------------- | ------------------------------- | ----- |
| `id`                       | bigint PK             |                                 | P1    |
| `ulid`                     | char(26) unique       |                                 | P1    |
| `board_id`                 | bigint FK             | `boards.id`, CASCADE            | P1    |
| `name`                     | varchar(64)           |                                 | P1    |
| `position`                 | integer               | Order on the board              | P1    |
| `color_token`              | varchar(32)           | Status color from design system | P1    |
| `is_terminal_success`      | boolean default false | E.g., "Paid"                    | P1    |
| `is_terminal_failure`      | boolean default false | E.g., "Cancelled"               | P1    |
| `created_at`, `updated_at` | timestamptz           |                                 | P1    |

### `board_automations`

Maps system events to column moves.

| Column                     | Type                 | Notes                                                           | Phase |
| -------------------------- | -------------------- | --------------------------------------------------------------- | ----- |
| `id`                       | bigint PK            |                                                                 | P1    |
| `ulid`                     | char(26) unique      |                                                                 | P1    |
| `board_id`                 | bigint FK            | `boards.id`, CASCADE                                            | P1    |
| `event_key`                | varchar(64)          | E.g., `assignment.draft_submitted`                              | P1    |
| `action_type`              | varchar(16)          | `move_to_column`, `none`                                        | P1    |
| `target_column_id`         | bigint FK null       | `board_columns.id`, SET NULL                                    | P1    |
| `condition`                | jsonb null           | Optional filter (e.g., only if brand has auto-approve disabled) | P1    |
| `is_enabled`               | boolean default true |                                                                 | P1    |
| `created_at`, `updated_at` | timestamptz          |                                                                 | P1    |

**Indexes:**

- `unique_board_automations_event` on (`board_id`, `event_key`)

### `board_cards`

| Column                     | Type             | Notes                              | Phase |
| -------------------------- | ---------------- | ---------------------------------- | ----- |
| `id`                       | bigint PK        |                                    | P1    |
| `ulid`                     | char(26) unique  |                                    | P1    |
| `board_id`                 | bigint FK        | `boards.id`, CASCADE               | P1    |
| `column_id`                | bigint FK        | `board_columns.id`, RESTRICT       | P1    |
| `assignment_id`            | bigint FK unique | `campaign_assignments.id`, CASCADE | P1    |
| `position`                 | integer          | Order within column                | P1    |
| `created_at`, `updated_at` | timestamptz      |                                    | P1    |

### `board_card_movements`

Append-only log of card movements.

| Column                 | Type             | Notes                     | Phase |
| ---------------------- | ---------------- | ------------------------- | ----- |
| `id`                   | bigint PK        |                           | P1    |
| `card_id`              | bigint FK        | `board_cards.id`, CASCADE | P1    |
| `from_column_id`       | bigint FK null   |                           | P1    |
| `to_column_id`         | bigint FK        |                           | P1    |
| `triggered_by`         | varchar(16)      | `event` or `user`         | P1    |
| `triggered_event_key`  | varchar(64) null | If event-triggered        | P1    |
| `triggered_by_user_id` | bigint FK null   | If user-triggered         | P1    |
| `reason`               | text null        |                           | P1    |
| `created_at`           | timestamptz      |                           | P1    |

---

## 11. Messaging

### `message_threads`

One thread per CampaignAssignment.

| Column                     | Type             | Notes                              | Phase |
| -------------------------- | ---------------- | ---------------------------------- | ----- |
| `id`                       | bigint PK        |                                    | P1    |
| `ulid`                     | char(26) unique  |                                    | P1    |
| `agency_id`                | bigint FK        | RESTRICT                           | P1    |
| `assignment_id`            | bigint FK unique | `campaign_assignments.id`, CASCADE | P1    |
| `last_message_at`          | timestamptz null |                                    | P1    |
| `created_at`, `updated_at` | timestamptz      |                                    | P1    |

### `messages`

| Column                     | Type             | Notes                                                     | Phase |
| -------------------------- | ---------------- | --------------------------------------------------------- | ----- |
| `id`                       | bigint PK        |                                                           | P1    |
| `ulid`                     | char(26) unique  |                                                           | P1    |
| `thread_id`                | bigint FK        | `message_threads.id`, CASCADE                             | P1    |
| `sender_user_id`           | bigint FK        | `users.id`, RESTRICT                                      | P1    |
| `sender_role`              | varchar(16)      | `creator`, `agency_user`, `brand_user`, `system`, `admin` | P1    |
| `kind`                     | varchar(16)      | `text`, `system`, `attachment_only`                       | P1    |
| `body`                     | text null        |                                                           | P1    |
| `attachments`              | jsonb null       | Array of {s3_path, mime_type, name, size_bytes}           | P1    |
| `system_event_key`         | varchar(64) null | For system messages                                       | P1    |
| `created_at`, `updated_at` | timestamptz      |                                                           | P1    |
| `deleted_at`               | timestamptz null |                                                           | P1    |

**Indexes:**

- `idx_messages_thread_created` on (`thread_id`, `created_at`)

### `message_read_receipts`

| Column       | Type        | Notes                  | Phase |
| ------------ | ----------- | ---------------------- | ----- |
| `id`         | bigint PK   |                        | P1    |
| `message_id` | bigint FK   | `messages.id`, CASCADE | P1    |
| `user_id`    | bigint FK   | `users.id`, CASCADE    | P1    |
| `read_at`    | timestamptz |                        | P1    |

**Indexes:**

- `unique_read_receipts_message_user` on (`message_id`, `user_id`)

---

## 12. Audit log

### `audit_logs`

Append-only. Every privileged action.

| Column         | Type              | Notes                                                               | Phase |
| -------------- | ----------------- | ------------------------------------------------------------------- | ----- |
| `id`           | bigint PK         |                                                                     | P1    |
| `ulid`         | char(26) unique   |                                                                     | P1    |
| `agency_id`    | bigint FK null    | If tenant-scoped action                                             | P1    |
| `actor_type`   | varchar(32)       | `user`, `system`, `webhook`                                         | P1    |
| `actor_id`     | bigint null       | If user-initiated                                                   | P1    |
| `actor_role`   | varchar(32) null  | Snapshot of role at action time                                     | P1    |
| `action`       | varchar(64)       | E.g., `creator.approved`, `payment.released`, `creator.blacklisted` | P1    |
| `subject_type` | varchar(64)       | E.g., `Creator`, `Payment`                                          | P1    |
| `subject_id`   | bigint null       |                                                                     | P1    |
| `subject_ulid` | char(26) null     | For human-readable lookup                                           | P1    |
| `reason`       | text null         | Mandatory for destructive/sensitive actions                         | P1    |
| `metadata`     | jsonb null        | Action-specific context                                             | P1    |
| `before`       | jsonb null        | Snapshot of relevant state before                                   | P1    |
| `after`        | jsonb null        | Snapshot of relevant state after                                    | P1    |
| `ip`           | inet null         |                                                                     | P1    |
| `user_agent`   | varchar(512) null |                                                                     | P1    |
| `created_at`   | timestamptz       |                                                                     | P1    |

**Indexes:**

- `idx_audit_actor` on (`actor_type`, `actor_id`)
- `idx_audit_subject` on (`subject_type`, `subject_id`)
- `idx_audit_action` on `action`
- `idx_audit_agency_created` on (`agency_id`, `created_at`)
- `idx_audit_created_at` on `created_at` (for retention queries)

**No `updated_at`. No `deleted_at`. Append-only.**

---

## 13. Admin (platform staff)

### `admin_users`

Catalyst Engine ops staff. Distinct from agency/brand/creator users — separate auth flow.

`admin_users` is a User where `type = 'platform_admin'`. Additional admin-specific fields go on `admin_profiles`:

### `admin_profiles`

| Column                     | Type             | Notes                                                              | Phase                         |
| -------------------------- | ---------------- | ------------------------------------------------------------------ | ----------------------------- |
| `id`                       | bigint PK        |                                                                    | P1                            |
| `user_id`                  | bigint FK unique | `users.id`, CASCADE                                                | P1                            |
| `admin_role`               | varchar(32)      | `super_admin`, `support`, `finance`, `security` (P2 expands roles) | P1 (`super_admin` only in P1) |
| `ip_allowlist`             | jsonb null       | Optional IP allowlist                                              | P1                            |
| `created_at`, `updated_at` | timestamptz      |                                                                    | P1                            |

### `admin_impersonation_sessions`

When an admin impersonates a user for support.

| Column                 | Type             | Notes                           | Phase |
| ---------------------- | ---------------- | ------------------------------- | ----- |
| `id`                   | bigint PK        |                                 | P1    |
| `ulid`                 | char(26) unique  |                                 | P1    |
| `admin_user_id`        | bigint FK        | `users.id`, RESTRICT            | P1    |
| `impersonated_user_id` | bigint FK        | `users.id`, RESTRICT            | P1    |
| `reason`               | text             | Mandatory                       | P1    |
| `ticket_reference`     | varchar(64) null | Support ticket ID if applicable | P1    |
| `started_at`           | timestamptz      |                                 | P1    |
| `ended_at`             | timestamptz null |                                 | P1    |
| `ip`                   | inet             |                                 | P1    |
| `created_at`           | timestamptz      |                                 | P1    |

---

## 14. Notifications

### `notifications` (Laravel default + extended)

Standard Laravel notifications table extended for in-app notification center.

| Column                     | Type             | Notes                    | Phase |
| -------------------------- | ---------------- | ------------------------ | ----- |
| `id`                       | char(36) PK      | UUID per Laravel default | P1    |
| `type`                     | varchar(255)     | Notification class name  | P1    |
| `notifiable_type`          | varchar(255)     |                          | P1    |
| `notifiable_id`            | bigint           |                          | P1    |
| `data`                     | jsonb            |                          | P1    |
| `read_at`                  | timestamptz null |                          | P1    |
| `created_at`, `updated_at` | timestamptz      |                          | P1    |

### `notification_preferences`

| Column                     | Type                 | Notes                          | Phase |
| -------------------------- | -------------------- | ------------------------------ | ----- |
| `id`                       | bigint PK            |                                | P1    |
| `user_id`                  | bigint FK            | `users.id`, CASCADE            | P1    |
| `channel`                  | varchar(16)          | `email`, `in_app`, `push` (P2) | P1    |
| `event_key`                | varchar(64)          |                                | P1    |
| `is_enabled`               | boolean default true |                                | P1    |
| `created_at`, `updated_at` | timestamptz          |                                | P1    |

---

## 15. Feature flags

### `feature_flags`

Laravel Pennant table (auto-managed) plus our custom additions.

| Column                     | Type              | Notes                                              | Phase |
| -------------------------- | ----------------- | -------------------------------------------------- | ----- |
| `id`                       | bigint PK         |                                                    | P1    |
| `name`                     | varchar(128)      |                                                    | P1    |
| `scope`                    | varchar(255) null | Per-tenant scope (`agency:123`) or null for global | P1    |
| `value`                    | jsonb             | Boolean or richer config                           | P1    |
| `created_at`, `updated_at` | timestamptz       |                                                    | P1    |

---

## 16. Files & uploads

### `files`

Centralized table for tracking S3-stored files. Other tables reference this when they need file metadata beyond just a path.

| Column                     | Type                  | Notes                                                                                                                                               | Phase               |
| -------------------------- | --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------- |
| `id`                       | bigint PK             |                                                                                                                                                     | P1                  |
| `ulid`                     | char(26) unique       |                                                                                                                                                     | P1                  |
| `agency_id`                | bigint FK null        | If tenant-scoped                                                                                                                                    | P1                  |
| `uploaded_by_user_id`      | bigint FK null        |                                                                                                                                                     | P1                  |
| `kind`                     | varchar(32)           | `creator_avatar`, `creator_portfolio_video`, `creator_portfolio_image`, `draft_media`, `contract_pdf`, `agency_logo`, `brand_logo`, `export_report` | P1                  |
| `original_filename`        | varchar(255)          |                                                                                                                                                     | P1                  |
| `s3_bucket`                | varchar(64)           |                                                                                                                                                     | P1                  |
| `s3_path`                  | varchar(512)          |                                                                                                                                                     | P1                  |
| `mime_type`                | varchar(64)           |                                                                                                                                                     | P1                  |
| `size_bytes`               | bigint                |                                                                                                                                                     | P1                  |
| `checksum`                 | varchar(128) null     | sha256                                                                                                                                              | P1                  |
| `is_public`                | boolean default false | Determines bucket and signed-URL policy                                                                                                             | P1                  |
| `metadata`                 | jsonb null            | Width/height/duration etc.                                                                                                                          | P1                  |
| `virus_scanned_at`         | timestamptz null      |                                                                                                                                                     | P2 (column from P1) |
| `virus_scan_result`        | varchar(16) null      | `clean`, `infected`, `error`                                                                                                                        | P2 (column from P1) |
| `created_at`, `updated_at` | timestamptz           |                                                                                                                                                     | P1                  |
| `deleted_at`               | timestamptz null      |                                                                                                                                                     | P1                  |

---

## 17. Integration tracking

### `integration_events`

Append-only log of inbound webhook events from third-party providers. Used for idempotency and debugging.

| Column              | Type             | Notes                                                              | Phase |
| ------------------- | ---------------- | ------------------------------------------------------------------ | ----- |
| `id`                | bigint PK        |                                                                    | P1    |
| `provider`          | varchar(32)      | `stripe`, `meta`, `tiktok`, `youtube`, `persona`, `docusign`, etc. | P1    |
| `provider_event_id` | varchar(255)     | For idempotency                                                    | P1    |
| `event_type`        | varchar(128)     |                                                                    | P1    |
| `payload`           | jsonb            | Full webhook payload                                               | P1    |
| `processed_at`      | timestamptz null |                                                                    | P1    |
| `processing_error`  | text null        |                                                                    | P1    |
| `received_at`       | timestamptz      |                                                                    | P1    |

**Indexes:**

- `unique_integration_provider_event` on (`provider`, `provider_event_id`)

### `integration_credentials`

Stored credentials for integrations.

| Column                     | Type             | Notes                                                              | Phase |
| -------------------------- | ---------------- | ------------------------------------------------------------------ | ----- |
| `id`                       | bigint PK        |                                                                    | P1    |
| `agency_id`                | bigint FK null   | Per-agency creds (e.g., custom DocuSign account) — null for global | P1    |
| `provider`                 | varchar(32)      |                                                                    | P1    |
| `credentials`              | jsonb            | Encrypted                                                          | P1    |
| `expires_at`               | timestamptz null |                                                                    | P1    |
| `created_at`, `updated_at` | timestamptz      |                                                                    | P1    |

---

## 18. Search & analytics (designed for, not built P1)

### `search_index_creators` `[design-only, P2/P3]`

When migrating from Postgres FTS to Meilisearch/OpenSearch, this is the source-of-truth table the indexer reads. Phase 1 uses Postgres FTS directly on `creators` table.

### `analytics_events` `[design-only, P3]`

Self-hosted product analytics events table if PostHog/Amplitude not chosen. Phase 1 uses external tool only.

---

## 19. GDPR

### `data_export_requests`

Track user-initiated GDPR exports.

| Column                     | Type               | Notes                                         | Phase |
| -------------------------- | ------------------ | --------------------------------------------- | ----- |
| `id`                       | bigint PK          |                                               | P1    |
| `ulid`                     | char(26) unique    |                                               | P1    |
| `requested_by_user_id`     | bigint FK          |                                               | P1    |
| `subject_type`             | varchar(64)        | `User`, `Creator`, `Agency`                   | P1    |
| `subject_id`               | bigint             |                                               | P1    |
| `status`                   | varchar(16)        | `pending`, `processing`, `complete`, `failed` | P1    |
| `requested_at`             | timestamptz        |                                               | P1    |
| `completed_at`             | timestamptz null   |                                               | P1    |
| `download_url`             | varchar(2048) null | Signed S3 URL                                 | P1    |
| `expires_at`               | timestamptz null   | Download link expiration                      | P1    |
| `created_at`, `updated_at` | timestamptz        |                                               | P1    |

### `data_erasure_requests`

| Column                     | Type             | Notes                                                      | Phase |
| -------------------------- | ---------------- | ---------------------------------------------------------- | ----- |
| `id`                       | bigint PK        |                                                            | P1    |
| `ulid`                     | char(26) unique  |                                                            | P1    |
| `requested_by_user_id`     | bigint FK        |                                                            | P1    |
| `subject_type`             | varchar(64)      |                                                            | P1    |
| `subject_id`               | bigint           |                                                            | P1    |
| `status`                   | varchar(16)      | `pending`, `approved`, `rejected`, `executing`, `complete` | P1    |
| `approved_by_user_id`      | bigint FK null   | Admin approval                                             | P1    |
| `reason_for_rejection`     | text null        |                                                            | P1    |
| `requested_at`             | timestamptz      |                                                            | P1    |
| `executed_at`              | timestamptz null |                                                            | P1    |
| `created_at`, `updated_at` | timestamptz      |                                                            | P1    |

---

## 20. Cross-cutting traits

### `Audited` trait

Models that are audited use the `Audited` trait. The trait observes `created`, `updated`, `deleted` Eloquent events and writes to `audit_logs`.

Models with `Audited`: Creator, Agency, Brand, Campaign, CampaignAssignment, Contract, Payment, AgencyCreatorRelation, BrandCreatorBlacklist, User (for status changes), CreatorPayoutMethod, CreatorTaxProfile.

### `BelongsToAgency` trait

Tenant-scoped models use the `BelongsToAgency` trait. The trait:

- Adds a global scope filtering by `agency_id`
- Defines the `agency()` relationship
- Validates `agency_id` is set on model events

Models with `BelongsToAgency`: Brand, Campaign, CampaignAssignment, Board, BoardColumn, BoardCard, MessageThread, AgencyCreatorRelation (composite), Payment.

### `HasUlids` trait

Provided by Laravel. Adds the `ulid` column auto-population on create.

### `SoftDeletes` trait

Provided by Laravel. Models that should be soft-deleted use it.

---

## 21. Migration ordering for Phase 1

Phase 1 migrations are applied in this dependency order:

1. `users` (no dependencies)
2. Laravel default tables: sessions, password_reset_tokens, personal_access_tokens, notifications, feature_flags
3. `agencies`
4. `agency_users`
5. `admin_profiles`
6. `brands`
7. `creators`
8. `creator_social_accounts`
9. `creator_portfolio_items`
10. `creator_availability_blocks`
11. `creator_tax_profiles`
12. `creator_payout_methods`
13. `creator_kyc_verifications`
14. `agency_creator_relations`
15. `brand_creator_blacklists`
16. `contract_templates`
17. `contracts`
18. `campaigns`
19. `campaign_assignments`
20. `campaign_drafts`
21. `campaign_posted_content`
22. `payments`
23. `payment_events`
24. `boards`
25. `board_columns`
26. `board_automations`
27. `board_cards`
28. `board_card_movements`
29. `message_threads`
30. `messages`
31. `message_read_receipts`
32. `notification_preferences`
33. `audit_logs`
34. `admin_impersonation_sessions`
35. `files`
36. `integration_events`
37. `integration_credentials`
38. `data_export_requests`
39. `data_erasure_requests`

Each migration is a separate timestamped file. Forward and reverse tested.

---

## 22. Reserved column names (do not use for business data)

These columns are reserved by Laravel or our conventions and have specific meanings:

`id`, `ulid`, `created_at`, `updated_at`, `deleted_at`, `agency_id` (means tenant scope), `email_verified_at`, `remember_token`.

Do not use these names for business meaning that conflicts with their conventions.

---

## 23. Encryption at the application layer

The following columns are encrypted at the application layer using Laravel's `encrypted` cast:

- `users.two_factor_secret`
- `users.two_factor_recovery_codes`
- `creator_social_accounts.oauth_access_token`
- `creator_social_accounts.oauth_refresh_token`
- `creator_tax_profiles.legal_name`
- `creator_tax_profiles.tax_id`
- `creator_tax_profiles.address`
- `creator_kyc_verifications.decision_data`
- `integration_credentials.credentials`

---

## 24. Phase-by-phase additions summary

| Phase | What's added                                                                                                                                                                                                                                                                                                                             |
| ----- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P1    | Everything marked [P1] above. The full skeleton of the system.                                                                                                                                                                                                                                                                           |
| P2    | `brand_users` table. Activate `brand_user` role. Activate `client_review_*` columns on drafts. Add Google Calendar sync columns activation. Activate `appeal_*` columns on agency_creator_relations. Add metrics_history activation. Add `payout_speed` activation. Add `virus_scan` activation. Mobile push notification columns added. |
| P3    | Activate marketplace columns on campaigns. Activate `tier` on creators. Add ML-related tables (model versions, predictions, embeddings). AI QC columns activated on drafts. Public API token tables expanded.                                                                                                                            |
| P4    | Vertical AI agent state tables. Multi-region replication tables. Advanced compliance reporting tables. Affiliate / performance attribution tables. Content licensing marketplace tables.                                                                                                                                                 |

The fundamental shape never changes after Phase 1. Only additions.

---

**End of data model. This is the source of truth. Migrations follow this schema.**
