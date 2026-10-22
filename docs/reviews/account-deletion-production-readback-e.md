# AH-104c — production read-back E (read-only, for the colleague)

**Purpose.** The first real erasure (AH-104c, C4) cancels a creator's open assignments, refuses on delivered work, and notifies real agency users. The earlier read-backs counted 709 assignments with 0 in a
payment state, and did **not** count the non-terminal ones or the delivered ones. These queries do. Asked 2026-10-22 (inventory Q13).

**Safe by construction.** Every statement is a `SELECT` of **counts only**: no name, email, ulid, id or text column is returned, so the output can be pasted into a ticket. They run inside a
`READ ONLY` transaction that is rolled back, with a statement timeout. Run them on the **production PostgreSQL** with a read-only role if one exists (any role that can `SELECT` is enough). Nothing is
written, no lock beyond a normal read is taken.

## 1. The queries

**Checked:** the script ran to the end on the local Postgres container (read-only, rolled back) and returned the walk data's counts; it has not been run on production.

Save as `readback_e.sql` and run `psql "$PROD_DATABASE_URL" -X -f readback_e.sql` (or paste into the session).

```sql
BEGIN READ ONLY;
SET LOCAL statement_timeout = '30s';

-- E1. Every assignment by state (baseline: the total should match the earlier count of 709, give or take
--     what was created since).
SELECT status,
       count(*)                                       AS assignments,
       count(DISTINCT creator_id)                     AS creators,
       count(*) FILTER (WHERE deleted_at IS NOT NULL) AS soft_deleted
FROM campaign_assignments
GROUP BY status
ORDER BY status;

-- E2. What the erasure rider would cancel today: not soft-deleted, not in a terminal state.
SELECT status,
       count(*)                   AS would_cancel,
       count(DISTINCT creator_id) AS creators,
       count(DISTINCT agency_id)  AS agencies
FROM campaign_assignments
WHERE deleted_at IS NULL
  AND status NOT IN ('declined', 'rejected', 'completed_on_approval', 'payment_released', 'cancelled')
GROUP BY status
ORDER BY status;

-- E3. The same set, in total: how many creators have at least one open assignment, and how many agencies
--     would receive notices.
SELECT count(*)                   AS open_assignments,
       count(DISTINCT creator_id) AS creators_with_open_work,
       count(DISTINCT agency_id)  AS agencies_affected
FROM campaign_assignments
WHERE deleted_at IS NULL
  AND status NOT IN ('declined', 'rejected', 'completed_on_approval', 'payment_released', 'cancelled');

-- E4. Delivered or payment-eligible work (the states the new guard will refuse on): by state, and the
--     number of distinct creators who would be unerasable because of it. Soft-deleted rows are included
--     on purpose: the existing payment guard does not exclude them either.
SELECT status,
       count(*)                   AS assignments,
       count(DISTINCT creator_id) AS creators
FROM campaign_assignments
WHERE status IN ('live_verified', 'manually_verified', 'completed_on_approval', 'payment_held', 'payment_released')
GROUP BY status
ORDER BY status;

SELECT count(DISTINCT creator_id) AS creators_with_delivered_or_paid_work
FROM campaign_assignments
WHERE status IN ('live_verified', 'manually_verified', 'completed_on_approval', 'payment_held', 'payment_released');

-- E5. Any assignment carrying a payment id (the existing guard refuses on it).
SELECT count(*) AS assignments_with_payment_id
FROM campaign_assignments
WHERE payment_id IS NOT NULL;

-- E6. Deletion state of accounts (expect 0 and 0 while the flag is off and nothing has been erased).
SELECT count(*) FILTER (WHERE deletion_requested_at IS NOT NULL) AS deletion_requested,
       count(*) FILTER (WHERE erased_at IS NOT NULL)             AS erased
FROM users;

ROLLBACK;
```

## 2. The one non-SQL check

Whether the media bucket is **versioned** (the earlier read-back D left it open; it decides whether the version purge does anything and which IAM permissions the app role needs). Read-only:

```bash
aws s3api get-bucket-versioning --bucket <the media bucket name> --region <its region>
```

An empty result means versioning was never enabled; `"Status": "Enabled"` or `"Suspended"` means it was.

## 3. What to send back

The raw output of E1 to E6 and the bucket answer. No transformation needed; nothing in it identifies a person. I will record it in `account-deletion-production-readback.md` and read it against the plan's C4
preconditions.
