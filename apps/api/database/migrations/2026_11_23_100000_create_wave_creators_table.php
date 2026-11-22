<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE for existing data. One NEW, EMPTY table; no row is read, written or backfilled; no existing table is
 * altered. Its foreign keys take SHARE ROW EXCLUSIVE locks on the referenced tables for the length of the migration
 * transaction (milliseconds on an empty child): `agencies`, `waves`, `wave_markets`, `wave_personas`, `users` and
 * **`creators`** (busy production tables: sign-ups, profile saves). `SET LOCAL lock_timeout = '5s'` makes a blocked
 * migration fail fast instead of queueing every later write behind it. docs/reviews/pl-3-1-plan.md section 8 and
 * docs/reviews/pl-3-1-migration-rehearsal.md.
 *
 * PL-3.1 (M1): one creator's place in one market of one wave. ONE creator per wave (unique `(wave_id, creator_id)`): a creator
 * is one assignment on the wave's one campaign, with one status and one card. No status column: the status is derived from
 * the campaign (plan 1.4). `persona_id` is nullable (the match is optional); its composite foreign key makes "this persona
 * is in this wave AND this market" true in the database (MATCH SIMPLE: a null persona is not checked).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_creators', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->unsignedBigInteger('market_id');
            $table->foreign(['wave_id', 'market_id'], 'fk_wave_creators_market')
                ->references(['wave_id', 'id'])->on('wave_markets')->restrictOnDelete();

            // The creator row is anonymised in place and never deleted, so RESTRICT never blocks an erasure.
            $table->unsignedBigInteger('creator_id');
            $table->foreign('creator_id')->references('id')->on('creators')->restrictOnDelete();

            $table->unsignedBigInteger('persona_id')->nullable();
            $table->foreign(['wave_id', 'market_id', 'persona_id'], 'fk_wave_creators_persona')
                ->references(['wave_id', 'market_id', 'id'])->on('wave_personas')->restrictOnDelete();

            $table->unsignedSmallInteger('slot');
            $table->string('slot_code', 10);

            $table->unsignedBigInteger('added_by_user_id')->nullable();
            $table->foreign('added_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['wave_id', 'creator_id'], 'unique_wave_creators_wave_creator');
            $table->unique(['wave_id', 'id'], 'unique_wave_creators_wave_id_id');
            $table->unique(['market_id', 'slot'], 'unique_wave_creators_market_slot');
            $table->unique(['wave_id', 'market_id', 'slot_code'], 'unique_wave_creators_market_slot_code');
            $table->index(['wave_id', 'market_id', 'slot'], 'idx_wave_creators_wave_market_slot');
            $table->index('agency_id', 'idx_wave_creators_agency_id');
            $table->index('creator_id', 'idx_wave_creators_creator_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wave_creators ADD CONSTRAINT chk_wave_creators_slot CHECK (slot BETWEEN 1 AND 99)');
            DB::statement('ALTER TABLE wave_creators ADD CONSTRAINT chk_wave_creators_slot_code CHECK (char_length(trim(slot_code)) BETWEEN 2 AND 10)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_creators');
    }
};
