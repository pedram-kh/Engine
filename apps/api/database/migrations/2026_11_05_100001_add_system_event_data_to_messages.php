<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * AH-118 (campaign rules), item 2 — the data a system message needs to render
 * its line. A system message has so far been a bare `system_event_key`; the
 * "offer changed from X to Y" line needs the two amounts, so this adds ONE
 * nullable jsonb column, `system_event_data`. Nullable add: metadata-only on
 * Postgres, no row rewritten, no backfill, and every existing message (and
 * every system message that has no data) stays NULL.
 *
 * The column holds money amounts and a currency code only — never free text and
 * never anything about a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->jsonb('system_event_data')->nullable()->after('system_event_key');
        });
    }

    /**
     * Honest inverse, with one thing it cannot restore: dropping the column
     * discards the amounts of every "offer changed" line already written; the
     * lines themselves remain and fall back to the generic system text. Take a
     * snapshot first if this is ever rolled back against a populated database.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('system_event_data');
        });
    }
};
