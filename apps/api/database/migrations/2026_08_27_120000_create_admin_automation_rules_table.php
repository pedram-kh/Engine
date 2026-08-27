<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates `admin_automation_rules` — platform-level "when X happens, do Y"
 * rules an operator configures from the admin panel (AH-090, D1).
 *
 * SCHEMA-ONLY: no existing table is altered and nothing is backfilled. The table
 * ships EMPTY and the feature is forward-only — no approved creator that already
 * exists is read, let alone connected (D7). At deploy the reachable population is
 * zero, and it stays zero until someone deliberately creates a rule AND turns on
 * the `platform_automations_enabled` flag (default OFF).
 *
 * Registry-shaped on purpose (`event` × `action` × `config`), mirroring
 * `board_automations`' proven shape one level up: that table keys agency-scoped
 * card moves off an event; this one keys PLATFORM-scoped operations off a
 * platform event. v1 ships exactly one pair — `creator.approved` ×
 * `connect_to_agency` — but the shape was checked against the two future rules
 * the inventory posed ("on approval → add to talent pool", "on KYC pass → notify
 * agency"): both need a new event hook and a new action handler, NEITHER needs a
 * migration. `config` (jsonb) carries whatever a given action needs — for v1 the
 * target agency's ULID.
 *
 * The agency reference lives INSIDE `config` rather than as a real FK column,
 * because `config` belongs to the action, not to the table: a future action may
 * target a pool, a locale, or nothing. The cost is stated plainly — no
 * referential integrity, so a deleted or suspended agency leaves a rule
 * broken-but-present, exactly as a deleted target column does for a board
 * automation (§14.4). The job's live re-check is the enforcement, and the admin
 * list renders the broken state rather than hiding it.
 *
 * NO uniqueness constraint (D1, recorded choice): several enabled rules may
 * watch the same event, and two rules naming the same agency are safe because
 * the connect operation is idempotent — the second fire reports
 * `skipped:already_connected` instead of writing twice.
 *
 * `last_fired_at` / `fire_count` are the cheap fire trace — one in-place update
 * on a successful fire, so an operator can see a rule is alive without reading
 * the audit log. They are a TRACE, not the history: the audit row per fire is
 * the record.
 *
 * `down()` is a true structural inverse with a genuinely lossy content inverse:
 * dropping the table discards every configured rule and its trace, and a
 * redeploy comes back with an empty table (nothing re-fires, because the hook
 * finds nothing to read). Relations created by past fires deliberately SURVIVE a
 * rollback — they are real relationships, not automation state, and unwinding
 * them is not this migration's business (D7: no disable-cascade, ever).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            // The operator's own label. Required, because it is what an audit
            // reason names when the rule fires ("Auto-connected by rule #3
            // (Approved → Northwind)") and what the rules list reads as its
            // first column — deriving one from event+action would give every v1
            // rule the identical name, which is useless precisely when an
            // operator runs several.
            $table->string('name', 120);

            // See AutomationEvent / AutomationAction. Plain varchars with no DB
            // CHECK constraint (the house posture — adding a case needs no
            // migration; the enums plus their tests are the documentation).
            $table->string('event', 64);
            $table->string('action', 64);

            $table->jsonb('config')->nullable();
            $table->boolean('enabled')->default(true);

            $table->timestamp('last_fired_at')->nullable();
            $table->unsignedInteger('fire_count')->default(0);

            $table->timestamps();

            // The dispatch-time query's exact shape: "enabled rules watching
            // this event". Read on every creator approval, so it is indexed
            // even though the table is expected to hold tens of rows.
            $table->index(['event', 'enabled'], 'admin_automation_rules_event_enabled_index');
        });
    }

    public function down(): void
    {
        // Lossy by nature — see the class docblock. Rules and their fire traces
        // are gone; the relations they created are not, by design.
        Schema::dropIfExists('admin_automation_rules');
    }
};
