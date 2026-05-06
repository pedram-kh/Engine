# 08 — Database Evolution

> **Status: Always active reference. Defines how the database schema evolves after Phase 1 ships and real users have real data. Cursor must follow these patterns on every migration that touches a populated table.**

After Phase 1 launches, every schema change carries risk. Live data must not be lost. Live users must not see downtime. Code from before and after the change must coexist briefly. This document defines exactly how to do that.

---

## 1. The core principle

**Every schema change after Phase 1 is split into multiple deploys.** Single-deploy schema changes are forbidden on populated tables.

The pattern is **Expand → Migrate → Contract**:

1. **Expand:** Add new columns / tables alongside the old structure. New code reads/writes both old and new. Old code keeps working.
2. **Migrate:** Backfill data from old structure to new. Verify integrity. Switch primary read source to new structure. Old code still works (because old columns still exist).
3. **Contract:** After verification, remove the old structure in a later deploy. By this point, only new code is running.

Each step is a separate PR, separate migration, separate deploy.

---

## 2. Why single-deploy migrations are dangerous

Consider this naive change: "Rename column `name` to `display_name` on the creators table."

Single-deploy approach:

1. Migration drops `name`, adds `display_name`, copies data.
2. Code now reads `display_name`.

What goes wrong:

- During deploy, old containers are still running old code that reads `name`. Migration runs first; old code now hits a missing column. Errors.
- If something goes wrong with the migration (timeout, deadlock, partial completion), the database is in an inconsistent state.
- Rolling back means another destructive migration (drop `display_name`, add `name`, copy data back). Risk compounded.
- Long-running tables (millions of rows) lock during the migration. Site freezes.

Expand/migrate/contract solves all of this. It's slower (three deploys) but eliminates downtime and rollback risk.

---

## 3. Worked examples

### 3.1 Adding a nullable column

**Lowest risk; can be done in a single deploy.**

```php
// Migration
Schema::table('campaigns', function (Blueprint $table) {
    $table->string('priority', 16)->nullable()->after('status');
});
```

- Old code keeps working (it ignores the new column).
- New code uses the new column.
- Default value is null until application logic populates it.

**Single deploy is safe** because:

- The change is additive
- No data needs to move
- Old code is unaffected

### 3.2 Adding a non-nullable column with a default

**Low risk if the default is sensible.**

For small tables, this can be done in a single deploy:

```php
Schema::table('campaigns', function (Blueprint $table) {
    $table->string('priority', 16)->default('normal')->after('status');
});
```

For large tables (>100k rows), the default-value backfill can lock the table for too long. In that case, do it in two deploys:

**Deploy 1:** Add nullable column, default to NULL.

```php
$table->string('priority', 16)->nullable()->after('status');
```

**Deploy 2 (queued migration):** Backfill in batches.

```php
Campaign::whereNull('priority')->chunkById(1000, function ($campaigns) {
    foreach ($campaigns as $campaign) {
        $campaign->update(['priority' => 'normal']);
    }
});
```

**Deploy 3:** Make the column non-nullable.

```php
$table->string('priority', 16)->nullable(false)->change();
```

### 3.3 Renaming a column

**Three deploys mandatory on populated tables.**

Suppose we want to rename `creators.name` to `creators.display_name`.

**Deploy 1 — Expand:**

```php
// Migration
Schema::table('creators', function (Blueprint $table) {
    $table->string('display_name', 160)->nullable()->after('name');
});
```

Code change in this deploy:

- Writes go to BOTH `name` and `display_name`.
- Reads still come from `name`.
- A queued backfill job copies existing `name` to `display_name`.

```php
// Creator model
public function setNameAttribute(string $value): void
{
    $this->attributes['name'] = $value;
    $this->attributes['display_name'] = $value;
}
```

```php
// BackfillCreatorDisplayNameJob
public function handle(): void
{
    Creator::whereNull('display_name')->chunkById(1000, function ($creators) {
        foreach ($creators as $creator) {
            $creator->update(['display_name' => $creator->name]);
        }
    });
}
```

**Deploy 2 — Migrate:**

After backfill is verified complete:

Code change:

- Reads now come from `display_name`.
- Writes still go to BOTH (defense against rollback).

```php
public function getNameAttribute(): string
{
    return $this->attributes['display_name'];
}
```

Make `display_name` non-nullable:

```php
Schema::table('creators', function (Blueprint $table) {
    $table->string('display_name', 160)->nullable(false)->change();
});
```

**Deploy 3 — Contract:**

After several days of stable operation:

Code change:

- Writes go ONLY to `display_name`.
- Remove the dual-write logic.

Migration drops the old column:

```php
Schema::table('creators', function (Blueprint $table) {
    $table->dropColumn('name');
});
```

Three deploys, weeks apart in some cases. Zero downtime. Trivial rollback at any step.

### 3.4 Splitting a table

Suppose we want to extract `creator_tax_profile` data from the `creators` table into a separate `creator_tax_profiles` table (this is actually our Phase 1 design — but if we ever needed to do it after-the-fact, this is how).

**Deploy 1 — Expand:** Create the new table. Don't move data yet.

**Deploy 2 — Dual-write:** Code writes to both old and new. Backfill job copies existing data.

**Deploy 3 — Switch reads:** Code reads from new. Continues writing to both for safety.

**Deploy 4 — Contract:** Stop writing to old. Drop old columns.

### 3.5 Changing a column's type

The most risky kind of change. Always multi-deploy.

Suppose we want to change `campaigns.budget_minor_units` from `bigint` to `numeric(20, 0)` (we wouldn't, but as an example).

**Deploy 1 — Expand:** Add `budget_minor_units_v2` as the new type. Dual-write.

**Deploy 2 — Backfill:** Queued job copies old to new.

**Deploy 3 — Switch reads.**

**Deploy 4 — Contract:** Drop old column. Optionally rename new to old (which is itself another expand/migrate/contract).

### 3.6 Adding an index

Indexes can be expensive to add on large tables. Use `CREATE INDEX CONCURRENTLY` (Postgres) which doesn't lock the table.

```php
public function up(): void
{
    DB::statement('CREATE INDEX CONCURRENTLY idx_campaigns_brand_status ON campaigns (brand_id, status)');
}

public function down(): void
{
    DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_campaigns_brand_status');
}
```

Note: `CREATE INDEX CONCURRENTLY` cannot run inside a transaction. Mark the migration with:

```php
public $withinTransaction = false;
```

---

## 4. Migration rules

### 4.1 Mandatory rules

1. **Every migration has a working `down()`.** Tested in CI.
2. **Migrations are idempotent.** Running twice doesn't break.
3. **No destructive change in a single migration with its replacement.** No "drop column X, add column Y" in one file.
4. **Long-running migrations are queued.** Not blocking deploys.
5. **No `truncate`, no `dropIfExists`, no `delete from` in production migrations.** Data destruction goes through dedicated scripts with explicit approvals.
6. **Foreign keys use explicit `ON DELETE` behavior.** `cascadeOnDelete()`, `restrictOnDelete()`, `nullOnDelete()`. Default behavior is forbidden.
7. **Migrations are reviewed before merge** even on solo workflow — they get a separate self-review pass.
8. **Migration file names are descriptive.** `2026_05_01_120000_add_priority_to_campaigns_table.php`, not `2026_05_01_120000_update_campaigns.php`.

### 4.2 Forbidden in single-deploy migrations on populated tables

- Renaming a column
- Changing a column's type
- Changing a column from nullable to non-nullable without a backfill
- Splitting or merging tables
- Removing a foreign key constraint
- Renaming a table
- Adding a unique constraint without verification of no duplicates
- Removing a value from an enum

These all require expand/migrate/contract.

### 4.3 Allowed in single-deploy migrations

- Adding a nullable column (any size)
- Adding a column with a default value (small tables only — define small as <50k rows)
- Adding a new table
- Adding an index using `CREATE INDEX CONCURRENTLY`
- Dropping an unused index
- Adding a foreign key (if data is known to satisfy it, otherwise expand/migrate/contract)
- Adding a check constraint (if data is known to satisfy it)

When in doubt, choose the multi-deploy path.

---

## 5. Migration execution

### 5.1 Local development

`php artisan migrate` runs migrations against the local database. Standard flow.

### 5.2 Staging

- CI runs migrations against staging on every deploy to staging.
- Migrations are tested against a recent snapshot of production data, not just empty staging tables.
- Failure on staging blocks production deploy.

### 5.3 Production

- **Automatic snapshot before every production migration.** RDS snapshot triggered by deploy script.
- **Migration runs as part of deploy**, BUT only after manual approval gate for migrations flagged "high risk."
- **Each migration logs its start and end time** in a dedicated log channel.
- **Long-running migrations** (> 1 minute) are queued, not run synchronously during deploy.

### 5.4 Migration risk classification

In the migration file's PHPDoc, declare risk:

```php
/**
 * @migration-risk low
 * Adds a nullable column. Safe single-deploy.
 */
class AddPriorityToCampaignsTable extends Migration
{
    public function up(): void { ... }
}
```

Risk levels:

- **`low`** — additive, no data movement, no locking concerns. Auto-deploys.
- **`medium`** — touches existing columns or larger tables but is reversible. Auto-deploys with notification.
- **`high`** — destructive, type changes, large table modifications, irreversible side effects. Manual approval gate before production deploy.

CI parses the risk level and routes the migration accordingly.

### 5.5 Rollback procedure

If a production migration goes wrong:

1. **Stop further deploys** immediately.
2. **Assess:** is rollback better than rolling forward? Often a quick fix-forward is safer.
3. **If rolling back:**
   - Revert the application deploy to previous version.
   - Run `php artisan migrate:rollback --step=1` if the migration's `down()` is reliable.
   - If `down()` isn't reliable, restore from the pre-migration snapshot. Acknowledge data loss for any writes between snapshot and rollback.
4. **Document the incident** in a post-mortem.
5. **Patch the migration** before re-attempting.

The pre-migration snapshot is the ultimate safety net. It must be tested at least quarterly to ensure restores actually work.

---

## 6. Special cases

### 6.1 Audit log table partitioning (Phase 2)

The `audit_logs` table will grow extremely fast. Phase 2 introduces monthly partitioning.

**Migration approach:**

1. Phase 1: write to a single `audit_logs` table.
2. Phase 2 transition (multi-deploy):
   - **Deploy 1:** Create `audit_logs_partitioned` as a partitioned table. Dual-write to both.
   - **Deploy 2:** Backfill historical data into partitioned table.
   - **Deploy 3:** Switch reads to partitioned table. Continue dual-writing.
   - **Deploy 4:** Stop writing to old. Drop old.
   - **Deploy 5:** Rename `audit_logs_partitioned` to `audit_logs`.

This is a Phase 2 task with its own dedicated planning doc when the time comes.

### 6.2 Soft-deleted data cleanup

Soft-deleted rows accumulate. A scheduled job hard-deletes them after the retention period:

```php
// HardDeleteSoftDeletedRecordsJob, runs nightly
// Different retention per table:
// - Creator profiles: retain soft-deleted for 7 years (financial / legal)
// - Campaigns: retain for 3 years
// - Messages: retain for 1 year
```

This is not a migration; it's a scheduled job. But it's part of the data evolution story.

### 6.3 Data anonymization for GDPR erasure

When a user's erasure request is approved, the `ExecuteDataErasureJob` runs:

- Sets PII fields to anonymized placeholders
- Nulls sensitive fields
- Deletes S3 files
- Maintains audit log integrity (entries reference anonymized identity but still exist)

Anonymization is reversible only if a backup is restored. By design, it's effectively irreversible.

### 6.4 Multi-tenancy expansion

If a future enterprise client demands schema-per-tenant isolation, the path:

- Existing row-level tenancy continues to work for most tenants.
- A new `dedicated_tenants` table tracks tenants moved to dedicated schemas.
- A migration script moves a single tenant's data from shared schema to a dedicated schema (with downtime for that tenant only, scheduled and communicated).
- Application logic routes queries to the right schema based on tenant.

This is a Phase 4 capability if ever needed. Not designed in detail until needed.

---

## 7. Migration testing requirements

### 7.1 Forward test

```php
test('migration adds the priority column', function () {
    expect(Schema::hasColumn('campaigns', 'priority'))->toBeTrue();
});
```

### 7.2 Backwards test

```php
test('migration is reversible', function () {
    Artisan::call('migrate:rollback', ['--step' => 1]);
    expect(Schema::hasColumn('campaigns', 'priority'))->toBeFalse();
    Artisan::call('migrate'); // restore for other tests
});
```

### 7.3 Backwards-compatibility test

For multi-deploy migrations, between deploy 1 and deploy 2 both old and new code must work:

```php
test('old code still functions with expanded schema', function () {
    // Simulate old code by directly writing only to old column
    DB::table('creators')->insert([
        'name' => 'Old Code Creator',
        // Note: NOT setting display_name
    ]);

    // Application read still works (because we haven't switched reads yet)
    $creator = Creator::where('name', 'Old Code Creator')->first();
    expect($creator)->not->toBeNull();
});
```

### 7.4 Data integrity test

For backfill jobs:

```php
test('backfill copies all existing names to display_name', function () {
    Creator::factory()->count(100)->create();
    DB::table('creators')->update(['display_name' => null]); // simulate pre-backfill

    BackfillCreatorDisplayNameJob::dispatchSync();

    expect(Creator::whereNull('display_name')->count())->toBe(0);
});
```

---

## 8. Data seeding (separate from migrations)

### 8.1 Seeders for development

`database/seeders/` contains seeders that produce realistic local data: agencies, brands, creators, campaigns. Run via `php artisan db:seed`.

Seeders are NOT run in production. Production data is real data.

### 8.2 Reference data seeding

Some lookup data is "system-managed" (e.g., default contract templates, system status colors, default board column templates). This is seeded via dedicated migrations, not seeders, so it's environment-consistent:

```php
// 2026_05_01_120000_seed_default_board_column_templates.php
public function up(): void
{
    DB::table('board_column_templates')->insert([
        ['name' => 'To Define', 'color' => 'status-todefine', 'position' => 1, ...],
        ['name' => 'In Progress', 'color' => 'status-progress', 'position' => 2, ...],
        // ...
    ]);
}
```

### 8.3 Production data corrections

When production data needs correction (one-off, e.g., fixing a row that got into an inconsistent state):

- **Always go through code, never raw SQL.**
- Write a one-off Artisan command in `app/Console/Commands/OneOff/`.
- Command logs every change to the audit log with a reason.
- Command is reviewed before running.
- Command is committed to the repo for record.
- Command is deleted after a month (we don't keep ad-hoc commands forever).

---

## 9. Migration checklist (per migration PR)

```
- [ ] Migration risk level declared in PHPDoc
- [ ] up() implemented
- [ ] down() implemented and tested
- [ ] Migration is reversible (or explicitly documented why not)
- [ ] No destructive changes paired with replacements (expand/migrate/contract used if needed)
- [ ] Foreign keys have explicit ON DELETE behavior
- [ ] Indexes added for new query patterns
- [ ] Long-running operations queued, not synchronous
- [ ] Migration tested locally
- [ ] Migration tested against staging data snapshot
- [ ] If high-risk: manual approval gate documented
- [ ] If multi-deploy: which deploy is this and when does the next happen?
- [ ] Backfill job created and tested if needed
- [ ] Rollback procedure documented
```

---

## 10. Why we go to all this trouble

Catalyst Engine handles real money via Stripe Connect. Real creator livelihoods. Real agency client commitments. Real GDPR-relevant personal data.

The cost of one bad migration that loses creator data, corrupts payment state, or causes hours of downtime would be:

- Trust lost with the agency partner
- Possible legal exposure (GDPR Article 32 — appropriate technical measures)
- Real financial loss to creators
- Time spent recovering, rebuilding trust, and writing post-mortems

The cost of expand/migrate/contract is:

- A schema change that takes 3 deploys instead of 1
- Slightly more code complexity during the transition

The trade is overwhelmingly worth it. This discipline is non-negotiable.

---

**End of database evolution. Live data is sacred.**
