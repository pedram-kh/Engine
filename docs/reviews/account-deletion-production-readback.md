# AH-104a — Production read-back list (for the colleague)

**Status:** PROPOSED with the AH-104a plan ([`account-deletion-erasure-plan.md`](account-deletion-erasure-plan.md), Stage 0). Nothing here has been run.
**Why it exists:** the PII map is built from code and migrations. It could not see production. Eight things in it are marked "unknown" (map §8),
and the erasure design changes depending on the answers (the audit grants decide whether the scrub role is possible at all).
**What it is:** **read-only, aggregate, no PII.** Counts, flag values, grants, settings. No row contents, no emails, no names, no tokens, no secret values.
If an answer would require reading a person's data, stop and ask.

**How to run (every database item):** open a read-only session so nothing can write by accident:

```sql
BEGIN READ ONLY;
-- the queries below
ROLLBACK;
```

Run it from the production console with the **application's normal read access** (not a superuser, unless item C says otherwise). Paste each result back
into the **Answer** block under its item, exactly as printed, and say which environment and when. A query that errors is an answer; paste the error.

**Output goes to Pedram, then into `docs/runbooks/deploy-log.md`-style notes for this chunk** (not into chat history only). Nothing from this list is a
deploy step.

---

## A. Vendor and driver state (are any real providers live?)

Map §6 and the `integration_events`, `creator_kyc_verifications`, `creator_social_accounts` and `creator_payout_methods` rows depend on this.

**A1. Driver and provider values.** Values only, never secrets. Run from the app root:

```bash
php artisan tinker --execute="
foreach (['kyc','esign','social','payment'] as \$k) { echo \$k.' driver = '.config('integrations.'.\$k.'.driver').PHP_EOL; }
echo 'mail = '.config('mail.default').PHP_EOL;
echo 'session = '.config('session.driver').PHP_EOL;
echo 'queue = '.config('queue.default').PHP_EOL;
echo 'cache = '.config('cache.default').PHP_EOL;
echo 'filesystem default = '.config('filesystems.default').PHP_EOL;
echo 'pennant store = '.config('pennant.default').PHP_EOL;
echo 'stripe secret set = '.(config('integrations.payment.stripe.secret_key') ? 'yes' : 'no').PHP_EOL;
echo 'fcm project set = '.(config('integrations.push.fcm.project_id') ? 'yes' : 'no').PHP_EOL;
"
```

> If a config key path is wrong, tinker prints null or an error: that is not a finding. Read the same values from the deployed `.env` **names only**
> (`KYC_PROVIDER`, `ESIGN_PROVIDER`, `SOCIAL_PROVIDER`, `PAYMENT_PROVIDER`, `MAIL_MAILER`, `SESSION_DRIVER`, `QUEUE_CONNECTION`, `CACHE_STORE`,
> `FILESYSTEM_DISK`, `PENNANT_STORE`, and whether `STRIPE_SECRET_KEY` and `FCM_SERVICE_ACCOUNT_JSON_B64` are non-empty, **yes or no, not the value**).

**A2. Feature-flag state.** The flags that arm the vendor-facing flows:

```sql
SELECT name, scope, value FROM features
WHERE name IN ('kyc_verification_enabled','creator_payout_method_enabled','contract_signing_enabled',
               'social_verification_enabled','per_campaign_contract_enabled','push_notifications_enabled',
               'post_verified_mail_enabled','job_posted_notifications_enabled','incomplete_creator_nudge_enabled')
ORDER BY name, scope;
```

Also the **scope string format** (map §8: "`features.scope` format not verified"): `SELECT DISTINCT scope FROM features LIMIT 10;` **Redact any value that looks
like a user id**: replace the number with `N` and say you did. The shape is what is needed.

**A3. Has a real vendor ever been used?** Aggregate only:

```sql
SELECT provider, event_type, count(*) AS n, min(received_at) AS first_seen, max(received_at) AS last_seen
FROM integration_events GROUP BY provider, event_type ORDER BY provider, event_type;

SELECT provider, status, count(*) FROM creator_kyc_verifications GROUP BY provider, status;
SELECT provider, status, count(*) FROM creator_payout_methods GROUP BY provider, status;
SELECT platform, sync_status, count(*) FROM creator_social_accounts GROUP BY platform, sync_status;
SELECT signature_provider, status, count(*) FROM contracts GROUP BY signature_provider, status;
```

A provider named anything other than `mock` in any of these is the finding that matters (it means AH-104a must call a vendor, map §6).

**Answer A:** _(paste)_

---

## B. Row counts per affected table (sizes the dry-run and finds surprises)

All `count(*)`. One query so it is one paste:

```sql
SELECT 'users (all)'                          AS what, count(*) FROM users
UNION ALL SELECT 'users type=creator',                   count(*) FROM users WHERE type = 'creator'
UNION ALL SELECT 'users creator, never verified (prospect-shaped)', count(*) FROM users WHERE type = 'creator' AND email_verified_at IS NULL
UNION ALL SELECT 'users creator, name = email (bulk-invite prospects)', count(*) FROM users WHERE type = 'creator' AND name = email
UNION ALL SELECT 'users creator with 2FA secret',        count(*) FROM users WHERE type = 'creator' AND two_factor_secret IS NOT NULL
UNION ALL SELECT 'users creator suspended',              count(*) FROM users WHERE type = 'creator' AND is_suspended
UNION ALL SELECT 'users soft-deleted (any type)',        count(*) FROM users WHERE deleted_at IS NOT NULL
UNION ALL SELECT 'creators',                             count(*) FROM creators
UNION ALL SELECT 'creators soft-deleted',                count(*) FROM creators WHERE deleted_at IS NOT NULL
UNION ALL SELECT 'creators with date_of_birth',          count(*) FROM creators WHERE date_of_birth IS NOT NULL
UNION ALL SELECT 'creators with phone or whatsapp',      count(*) FROM creators WHERE phone IS NOT NULL OR whatsapp IS NOT NULL
UNION ALL SELECT 'creators with address',                count(*) FROM creators WHERE address_street IS NOT NULL
UNION ALL SELECT 'creators with content_companions',     count(*) FROM creators WHERE content_companions IS NOT NULL AND content_companions::text <> '[]'
UNION ALL SELECT 'creators with avatar_path',            count(*) FROM creators WHERE avatar_path IS NOT NULL
UNION ALL SELECT 'creator_tax_profiles',                 count(*) FROM creator_tax_profiles
UNION ALL SELECT 'creator_payout_methods',               count(*) FROM creator_payout_methods
UNION ALL SELECT 'creator_kyc_verifications',            count(*) FROM creator_kyc_verifications
UNION ALL SELECT 'creator_social_accounts',              count(*) FROM creator_social_accounts
UNION ALL SELECT 'creator_social_accounts with oauth token', count(*) FROM creator_social_accounts WHERE oauth_access_token IS NOT NULL
UNION ALL SELECT 'creator_portfolio_items',              count(*) FROM creator_portfolio_items
UNION ALL SELECT 'creator_availability_blocks',          count(*) FROM creator_availability_blocks
UNION ALL SELECT 'agency_creator_relations',             count(*) FROM agency_creator_relations
UNION ALL SELECT 'agency_creator_relations with live invitation token', count(*) FROM agency_creator_relations WHERE invitation_token_hash IS NOT NULL
UNION ALL SELECT 'brand_creator_blacklists',             count(*) FROM brand_creator_blacklists
UNION ALL SELECT 'talent_pool_creators',                 count(*) FROM talent_pool_creators
UNION ALL SELECT 'campaign_job_notifications',           count(*) FROM campaign_job_notifications
UNION ALL SELECT 'campaign_assignments',                 count(*) FROM campaign_assignments
UNION ALL SELECT 'campaign_assignments in a payment state', count(*) FROM campaign_assignments WHERE status IN ('payment_held','payment_released')
UNION ALL SELECT 'campaign_assignments with payment_id', count(*) FROM campaign_assignments WHERE payment_id IS NOT NULL
UNION ALL SELECT 'campaign_applications',                count(*) FROM campaign_applications
UNION ALL SELECT 'campaign_applications with note',      count(*) FROM campaign_applications WHERE note IS NOT NULL AND note <> ''
UNION ALL SELECT 'campaign_drafts',                      count(*) FROM campaign_drafts
UNION ALL SELECT 'contracts',                            count(*) FROM contracts
UNION ALL SELECT 'contracts signed',                     count(*) FROM contracts WHERE signed_at IS NOT NULL
UNION ALL SELECT 'messages',                             count(*) FROM messages
UNION ALL SELECT 'messages with attachments',            count(*) FROM messages WHERE attachments IS NOT NULL
UNION ALL SELECT 'relationship_messages',                count(*) FROM relationship_messages
UNION ALL SELECT 'relationship_messages with attachments', count(*) FROM relationship_messages WHERE attachments IS NOT NULL
UNION ALL SELECT 'message_read_receipts',                count(*) FROM message_read_receipts
UNION ALL SELECT 'relationship_message_read_receipts',   count(*) FROM relationship_message_read_receipts
UNION ALL SELECT 'message_email_debounces',              count(*) FROM message_email_debounces
UNION ALL SELECT 'notifications (all)',                  count(*) FROM notifications
UNION ALL SELECT 'notifications with data->creator_name',    count(*) FROM notifications WHERE data ? 'creator_name'
UNION ALL SELECT 'notifications with data->sender_name',     count(*) FROM notifications WHERE data ? 'sender_name'
UNION ALL SELECT 'notifications with data->counterparty_name', count(*) FROM notifications WHERE data ? 'counterparty_name'
UNION ALL SELECT 'user_notification_preferences',        count(*) FROM user_notification_preferences
UNION ALL SELECT 'personal_access_tokens',               count(*) FROM personal_access_tokens
UNION ALL SELECT 'sessions',                             count(*) FROM sessions
UNION ALL SELECT 'password_reset_tokens',                count(*) FROM password_reset_tokens
UNION ALL SELECT 'user_push_tokens',                     count(*) FROM user_push_tokens
UNION ALL SELECT 'push_deliveries',                      count(*) FROM push_deliveries
UNION ALL SELECT 'failed_jobs',                          count(*) FROM failed_jobs
UNION ALL SELECT 'jobs (queued now)',                    count(*) FROM jobs
UNION ALL SELECT 'tracked_jobs',                         count(*) FROM tracked_jobs
UNION ALL SELECT 'tracked_jobs bulk-invite with failures', count(*) FROM tracked_jobs WHERE result IS NOT NULL AND jsonb_array_length(COALESCE(result->'failures','[]'::jsonb)) > 0
UNION ALL SELECT 'integration_events',                   count(*) FROM integration_events
UNION ALL SELECT 'features',                             count(*) FROM features
UNION ALL SELECT 'admin_impersonation_sessions',         count(*) FROM admin_impersonation_sessions;
```

> `tracked_jobs.kind` for bulk invites is not asserted here; if the `result` shape differs the query returns 0, which is itself worth a line. If a table
> does not exist in production (a migration not yet run), the whole statement fails: re-run it without that line and **say which table was missing**
> (that is a migration-state finding for the deploy log).

**B2. `audit_logs` size and shape** (no row contents):

```sql
SELECT min(created_at) AS oldest, max(created_at) AS newest, count(*) AS rows FROM audit_logs;
SELECT actor_type, count(*) FROM audit_logs GROUP BY actor_type ORDER BY 2 DESC;
SELECT count(*) AS rows_with_ip              FROM audit_logs WHERE ip IS NOT NULL;
SELECT count(*) AS rows_with_user_agent      FROM audit_logs WHERE user_agent IS NOT NULL;
SELECT count(*) AS rows_with_metadata_email  FROM audit_logs WHERE metadata ? 'email';
SELECT count(*) AS failed_logins_no_actor    FROM audit_logs WHERE action = 'auth.login.failed' AND actor_id IS NULL;
SELECT count(*) AS snapshots_with_user_pii   FROM audit_logs WHERE subject_type ILIKE '%user' AND (before ? 'email' OR after ? 'email');
SELECT action, count(*) FROM audit_logs GROUP BY action ORDER BY 2 DESC LIMIT 25;
SELECT pg_size_pretty(pg_total_relation_size('audit_logs')) AS audit_logs_size;
```

> `auth.login.failed` is `AuditAction::AuthLoginFailed` (`AuditAction.php:30`). `subject_type` may be stored as a class name or an alias, hence the case-insensitive suffix match.
> If the failed-login count returns 0, run `SELECT DISTINCT action FROM audit_logs WHERE action ILIKE '%login%';` and paste that.

**Answer B:** _(paste both results)_

---

## C. `audit_logs` grants (decides whether D-B, the column-level scrub role, is possible)

The spec (`05-SECURITY-COMPLIANCE.md:198`) says the app role has INSERT and SELECT only. Nobody has read the real grants. **This is the answer the
whole audit scrub hangs on.**

```sql
-- Who am I, and who owns the table? (If the app connects as the OWNER, grants do not restrict it at all.)
SELECT current_user AS connected_as, session_user;
SELECT tablename, tableowner FROM pg_tables WHERE tablename = 'audit_logs';

-- Table-level grants
SELECT grantee, privilege_type FROM information_schema.role_table_grants
WHERE table_name = 'audit_logs' ORDER BY grantee, privilege_type;

-- Column-level grants (the mechanism D-B proposes)
SELECT grantee, column_name, privilege_type FROM information_schema.column_privileges
WHERE table_name = 'audit_logs' AND grantee NOT IN ('postgres') ORDER BY grantee, column_name, privilege_type;

-- Raw ACL
SELECT relname, relowner::regrole, relacl, relrowsecurity AS rls_enabled FROM pg_class WHERE relname = 'audit_logs';

-- Does anything else enforce append-only? (triggers, rules, row-level security policies)
SELECT tgname, tgenabled, pg_get_triggerdef(oid) FROM pg_trigger WHERE tgrelid = 'audit_logs'::regclass AND NOT tgisinternal;
SELECT rulename, definition FROM pg_rules WHERE tablename = 'audit_logs';
SELECT polname, polcmd, polroles::regrole[] FROM pg_policy WHERE polrelid = 'audit_logs'::regclass;

-- Which roles exist, and can the connected role create or alter roles / grants?
SELECT rolname, rolcanlogin, rolsuper, rolcreaterole FROM pg_roles WHERE rolname !~ '^pg_' ORDER BY rolname;
SELECT version();
```

Plus, **from the AWS side** (no database access needed):

- Which **database user** the web app, the queue worker and the **migration step** each connect as (name only; the `DB_USERNAME` of each process, not any password).
  If they are the same user, say so: it changes whether a separate erasure role is a real boundary.
- Whether an **"audit retention" role** (the spec's `05-SECURITY:199`) exists. The AH-104a plan assumes it does not.
- The **RDS engine and version** and whether the instance is **RDS** or Aurora.

**Answer C:** _(paste)_

---

## D. Copies the database cannot reach (the erasure ledger and notice depend on these)

- **RDS backups:** `BackupRetentionPeriod` (days), whether **PITR** is on, and **how many manual snapshots** exist and the oldest one's date.
  (`aws rds describe-db-instances` and `describe-db-snapshots`; counts and dates only.) This is the window in which a restore resurrects an erased creator,
  and it goes verbatim into the privacy notice.
- **S3 media bucket:** **versioning on or off**, any **lifecycle rule**, **MFA delete**, and whether **server access logs** go anywhere. Versioning matters
  most: with it on, deleting an avatar or portfolio object leaves a recoverable version, and AH-104a must delete versions too.
- **Audit archive:** the spec (`05-SECURITY:204-206`) says entries older than 12 months go to Glacier. **Is anything outside this repository exporting
  `audit_logs` rows** (a job, a Lambda, a DMS task, a scheduled `pg_dump`)? If yes, where, because a scrub in Postgres will not reach that copy. The repo
  has no retention or archive job (inventory §5.4).
- **Logs and error tracking:** whether application logs (CloudWatch or other) and Sentry are in use in production, and their retention. They can hold emails.
- **Mail provider:** which SMTP provider, and its message-log retention. (Named in the privacy notice, outside our delete.)

**Answer D:** _(paste)_

---

## E. Two questions that are not queries

1. **Is any real creator data in production today beyond the testers?** (Pedram's call; it sets how the first real erasure is staged.)
2. **Who may run the erasure command** in production, and from which machine? The AH-104c plan makes the Erase click an admin action; AH-104a needs
   to know who can run `php artisan` against production at all.

**Answer E:** _(Pedram)_

---

## What each answer changes in the plan

| If the answer is…                                                    | Then…                                                                                                                                                                                              |
| -------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| App connects to Postgres **as the table owner** (C)                  | Column-level grants do not bind it. The scrub role must be a **separate login**, and the app's own user must stop owning `audit_logs`: an infra change, stated in the plan, done by the colleague. |
| App role already has **no UPDATE** and the column grant can be added | D-B works as written: one `GRANT UPDATE (ip, user_agent, before, after, metadata) ON audit_logs TO <erasure_role>`.                                                                                |
| A trigger, rule or RLS policy blocks UPDATE (C)                      | The scrub role needs to be exempt from it (or the trigger is replaced by the column grant). Stated in the plan.                                                                                    |
| A non-`mock` provider appears in A3                                  | AH-104a gains a vendor-side step per map §6 **before** the local rows go, with its own dry-run line.                                                                                               |
| `campaign_assignments` in a payment state is **> 0** (B)             | The payment-state invariant is no longer hypothetical: the request path must refuse or refund first. **Stop and tell Pedram.**                                                                     |
| S3 versioning is **on** (D)                                          | Object erasure must delete **versions** and delete markers, and the plan says how it is proven.                                                                                                    |
| Audit rows are exported outside Postgres (D)                         | The scrub cannot be complete; the plan states the residual and the notice says so.                                                                                                                 |
| `failed_logins_no_actor` or `rows_with_metadata_email` is large (B2) | The email-value predicate (map S5) needs an index or batching, and the dry-run reports its cost.                                                                                                   |
