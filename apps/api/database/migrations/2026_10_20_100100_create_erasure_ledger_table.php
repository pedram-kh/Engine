<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * AH-104a Stage 1 — the erasure ledger. A NEW, additive table (`CREATE TABLE`
 * only): no existing row anywhere is read, rewritten or backfilled.
 *
 * It records THAT an erasure ran, never WHO the person was:
 *  - `user_ulid` is the pseudonymous key (audit rows and URLs already carry it;
 *    it is not the id and not the email). It is deliberately NOT a foreign key,
 *    so the record outlives anything that happens to the users table.
 *  - `state` walks started → db_done → completed (or incomplete).
 *  - `counts` holds numbers per step, nothing else.
 *  - `run_by_user_id` is the operator (an admin), SET NULL if that account is
 *    ever removed.
 *  - NEVER stored: email, name, ip, free text. The column list is pinned by an
 *    allowlist spec (ErasureLedgerSchemaTest).
 *
 * The write-through S3 mirror and the post-restore replay are Stage 7; a
 * database table alone cannot survive a restore of the database it lives in.
 *
 * Tenancy: platform-global, no `agency_id`; no route reads it yet (AH-104c).
 *
 * DEPLOY NOTE: the application role needs SELECT, INSERT, UPDATE on this table
 * and USAGE on `erasure_ledger_id_seq`; no DELETE is needed or wanted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erasure_ledger', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();

            $table->char('user_ulid', 26)->index();
            $table->string('state', 16);
            $table->json('counts')->nullable();
            $table->string('digest', 64)->nullable();

            $table->unsignedBigInteger('run_by_user_id')->nullable();
            $table->foreign('run_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erasure_ledger');
    }
};
