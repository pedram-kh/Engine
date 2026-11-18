<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * PROD-DATA RISK: NONE. One NEW, EMPTY table; no existing table is altered and no row is read, written or
 * backfilled. Nothing reads or writes `wave_steps` until the `waves_enabled` flag is on and a wave is created
 * or opened.
 *
 * PL-1.3 (docs/reviews/pl-1-3-plan.md section 1.1). The project tracker of a wave: one row per step.
 *
 *   - `step_key` is the stable identity (null for a step a person added); `name` is only shown text.
 *   - `stream` and `status` are plain strings; the PHP enums are the constraint (no migration for a new case).
 *   - `unique (wave_id, step_key)`: nulls never collide, so custom steps are free; it is the belt for provisioning.
 *   - `unique (wave_id, stream, position)`: two concurrent adds cannot take the same position.
 *   - `owner_client` is a word on the step ("Client"): no account, no access. `owner_user_id` and `owner_client`
 *     are never both set (service rule).
 *   - Foreign keys are RESTRICT, except the optional owner (null on delete).
 *
 * `SET LOCAL lock_timeout` as in the PL-1.2 migrations (PostgreSQL only). down() is lossy and a rollback tool only
 * before the flag is armed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::create('wave_steps', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();

            $table->unsignedBigInteger('wave_id');
            $table->foreign('wave_id')->references('id')->on('waves')->restrictOnDelete();

            $table->string('stream', 16);
            $table->string('step_key', 64)->nullable();
            $table->string('name', 160);
            $table->unsignedInteger('position');
            $table->string('status', 16)->default('not_started');

            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
            $table->boolean('owner_client')->default(false);
            $table->string('status_source', 8)->default('system');

            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->date('completed_on')->nullable();

            $table->timestamps();

            $table->unique(['wave_id', 'step_key'], 'unique_wave_steps_wave_key');
            $table->unique(['wave_id', 'stream', 'position'], 'unique_wave_steps_wave_stream_position');
            $table->index('wave_id', 'idx_wave_steps_wave_id');
            $table->index('agency_id', 'idx_wave_steps_agency_id');
        });
    }

    public function down(): void
    {
        // Lossy: destroys every step row. A rollback tool only before the flag is armed.
        Schema::dropIfExists('wave_steps');
    }
};
