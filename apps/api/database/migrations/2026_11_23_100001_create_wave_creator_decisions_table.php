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
 * altered. Its foreign keys take SHARE ROW EXCLUSIVE locks on `agencies`, `waves`, `wave_creators` and `users` for the length
 * of the migration (docs/reviews/pl-3-1-plan.md section 8). `SET LOCAL lock_timeout = '5s'`.
 *
 * PL-3.1 (M2): the client's decision on a creator, recorded by the agency (S4). Append-only; the newest row is current.
 * `note` and `comment` are free text about the creator and are SCRUBBED (set to null) when the creator is erased (Q6), so there
 * is deliberately NO "comment is required" constraint here (a scrub would violate it): the service enforces it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_creator_decisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->unsignedBigInteger('wave_creator_id');
            $table->foreign(['wave_id', 'wave_creator_id'], 'fk_wave_creator_decisions_creator')
                ->references(['wave_id', 'id'])->on('wave_creators')->restrictOnDelete();

            $table->string('status', 16);
            $table->string('client_name', 80);
            $table->string('note', 500)->nullable();
            $table->string('comment', 1000)->nullable();

            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->foreign('recorded_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['wave_creator_id', 'id'], 'idx_wave_creator_decisions_creator_id_id');
            $table->index('agency_id', 'idx_wave_creator_decisions_agency_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wave_creator_decisions ADD CONSTRAINT chk_wave_creator_decisions_status CHECK (status IN ('approved', 'suggest_another'))");
            DB::statement("ALTER TABLE wave_creator_decisions ADD CONSTRAINT chk_wave_creator_decisions_comment_only_suggest CHECK (status = 'suggest_another' OR comment IS NULL)");
            DB::statement("ALTER TABLE wave_creator_decisions ADD CONSTRAINT chk_wave_creator_decisions_note_only_approved CHECK (status = 'approved' OR note IS NULL)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wave_creator_decisions');
    }
};
