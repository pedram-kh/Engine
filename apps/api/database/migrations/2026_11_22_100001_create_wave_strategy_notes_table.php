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
 * PL-2.5 (K4, N3): internal notes. One thread per step per market, one per wave for USPs. INTERNAL: never serialised for a
 * client or a creator (plan section 1.7). `body` is plain text written by an agency user and may name a person.
 *
 *   - `market_id` is null only for `usps`: CHECK `(step = 'usps') = (market_id is null)`.
 *   - the composite FK `(wave_id, market_id)` points at `wave_markets (wave_id, id)`.
 *   - `author_user_id` is `nullOnDelete`: a removed user leaves the note and shows "Former member".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_strategy_notes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->string('step', 16);
            $table->unsignedBigInteger('market_id')->nullable();
            $table->foreign(['wave_id', 'market_id'], 'fk_wave_strategy_notes_market')
                ->references(['wave_id', 'id'])->on('wave_markets')->restrictOnDelete();

            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->foreign('author_user_id')->references('id')->on('users')->nullOnDelete();

            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['wave_id', 'step', 'market_id', 'created_at', 'id'], 'idx_wave_strategy_notes_thread');
            $table->index('agency_id', 'idx_wave_strategy_notes_agency_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wave_strategy_notes ADD CONSTRAINT chk_wave_strategy_notes_step CHECK (step IN ('usps', 'personas', 'matrix', 'concepts'))");
            DB::statement("ALTER TABLE wave_strategy_notes ADD CONSTRAINT chk_wave_strategy_notes_market CHECK ((step = 'usps') = (market_id IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_strategy_notes');
    }
};
