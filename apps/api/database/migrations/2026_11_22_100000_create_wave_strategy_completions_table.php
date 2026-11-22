<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE for existing data. One NEW, EMPTY table; no row is read, written or backfilled; no existing table
 * is altered. Its foreign keys take SHARE ROW EXCLUSIVE locks on `agencies`, `waves`, `wave_markets` and `users` for the
 * length of the migration (docs/reviews/pl-2-5-plan.md section 8; measured in pl-2-5-migration-rehearsal.md).
 *
 * PL-2.5 (K1): the strategist's "Mark as complete" on USPs (per wave), Personas and Matrix (per market). A row IS the
 * mark; Reopen deletes it (the audit log keeps the history). Concepts is never stored: it is derived from the concept
 * statuses.
 *
 *   - `market_id` is null only for `usps`: CHECK `(step = 'usps') = (market_id is null)`.
 *   - the composite FK `(wave_id, market_id)` points at `wave_markets (wave_id, id)`, so a mark cannot name a market of
 *     another wave. With a null `market_id` the FK is not checked, which is the intent.
 *   - one mark per wave and step, or per market: a unique expression index on `(wave_id, step, coalesce(market_id, 0))`
 *     (an expression index, as `wave_markets` uses for `lower(name)`; PostgreSQL and SQLite both run it).
 *   - every foreign key is RESTRICT except the completer: a removed user nulls it, the mark stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_strategy_completions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->string('step', 16);
            $table->unsignedBigInteger('market_id')->nullable();
            $table->foreign(['wave_id', 'market_id'], 'fk_wave_strategy_completions_market')
                ->references(['wave_id', 'id'])->on('wave_markets')->restrictOnDelete();

            $table->unsignedBigInteger('completed_by_user_id')->nullable();
            $table->foreign('completed_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->timestamp('completed_at');
            $table->timestamps();

            $table->index('agency_id', 'idx_wave_strategy_completions_agency_id');
            $table->index(['wave_id', 'step'], 'idx_wave_strategy_completions_wave_step');
        });

        DB::statement('CREATE UNIQUE INDEX unique_wave_strategy_completions_scope ON wave_strategy_completions (wave_id, step, coalesce(market_id, 0))');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wave_strategy_completions ADD CONSTRAINT chk_wave_strategy_completions_step CHECK (step IN ('usps', 'personas', 'matrix'))");
            DB::statement("ALTER TABLE wave_strategy_completions ADD CONSTRAINT chk_wave_strategy_completions_market CHECK ((step = 'usps') = (market_id IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_strategy_completions');
    }
};
