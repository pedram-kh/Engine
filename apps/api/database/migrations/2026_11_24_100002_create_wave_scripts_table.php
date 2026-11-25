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
 * transaction (milliseconds on an empty child): `agencies`, `waves`, `wave_markets`, `wave_creators`, `users` and
 * **`creators`** (a busy production table: sign-ups, profile saves). `SET LOCAL lock_timeout = '5s'` makes a blocked
 * migration fail fast instead of queueing every later write behind it. docs/reviews/pl-3-2-migration-rehearsal.md.
 *
 * PL-3.2a (M3): one script of one creator's slot. At most THREE LIVE scripts per creator per wave, and that is a database fact:
 * `number` is CHECKed 1..3 and unique per `(wave_id, creator_id)` among the LIVE rows only (a partial unique index,
 * `WHERE archived_at IS NULL`): an archived script keeps its number but does not hold it, so archiving one frees the place.
 * A script is archived (not deleted) when someone archives it in the editor, or when its slot is removed or replaced:
 * `wave_creator_id` becomes NULL and `archived_at` is set, and the CHECK says exactly one of the two is true. The
 * four-column foreign key makes "the slot is in this wave, this market and this creator's" true in the database; `ON UPDATE
 * CASCADE` on it moves the scripts with the slot when a Replace keeps them (the slot row is updated in place).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_scripts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->unsignedBigInteger('market_id');
            $table->foreign(['wave_id', 'market_id'], 'fk_wave_scripts_market')
                ->references(['wave_id', 'id'])->on('wave_markets')->restrictOnDelete();

            // The creator row is anonymised in place and never deleted, so RESTRICT never blocks an erasure.
            $table->unsignedBigInteger('creator_id');
            $table->foreign('creator_id')->references('id')->on('creators')->restrictOnDelete();

            // NULL while archived. MATCH SIMPLE: a null here switches the composite check off, as intended.
            $table->unsignedBigInteger('wave_creator_id')->nullable();
            $table->foreign(['wave_id', 'market_id', 'wave_creator_id', 'creator_id'], 'fk_wave_scripts_slot')
                ->references(['wave_id', 'market_id', 'id', 'creator_id'])->on('wave_creators')
                ->restrictOnDelete()->cascadeOnUpdate();
            $table->timestamp('archived_at')->nullable();

            $table->unsignedSmallInteger('number');
            $table->text('angle')->nullable();
            $table->unsignedSmallInteger('duration_seconds')->default(30);
            $table->char('language', 2)->nullable();

            // `revision` moves on EVERY change of the script or of any block of it (PL-3.3 reads it for "edited since").
            // `brief_revision` moves only on a change of the brief block: the brief's own concurrency token, so a hook save
            // never makes a brief save a conflict.
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('brief_revision')->default(1);

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->foreign('updated_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['wave_id', 'id'], 'unique_wave_scripts_wave_id_id');
            $table->unique(['wave_id', 'market_id', 'id'], 'unique_wave_scripts_wave_market_id');
            $table->index(['wave_id', 'market_id', 'creator_id'], 'idx_wave_scripts_wave_market_creator');
            $table->index('wave_creator_id', 'idx_wave_scripts_wave_creator_id');
            $table->index('agency_id', 'idx_wave_scripts_agency_id');
            $table->index('creator_id', 'idx_wave_scripts_creator_id');
        });

        // Live numbers are unique per creator per wave; archived scripts are outside it. A partial index (Postgres and SQLite both have it).
        DB::statement('CREATE UNIQUE INDEX unique_wave_scripts_live_wave_creator_number ON wave_scripts (wave_id, creator_id, number) WHERE archived_at IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wave_scripts ADD CONSTRAINT chk_wave_scripts_number CHECK (number BETWEEN 1 AND 3)');
            DB::statement('ALTER TABLE wave_scripts ADD CONSTRAINT chk_wave_scripts_duration CHECK (duration_seconds IN (15, 20, 30, 45, 60))');
            DB::statement('ALTER TABLE wave_scripts ADD CONSTRAINT chk_wave_scripts_archived CHECK ((archived_at IS NULL) = (wave_creator_id IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_scripts');
    }
};
