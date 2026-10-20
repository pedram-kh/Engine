<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * AH-104a Stage 1 — the explicit "this account was erased" marker.
 *
 * `users.erased_at` is the login block and the exclusion predicate for every
 * fan-out; `creators.erased_at` is what agency resources will read to emit an
 * `is_erased` flag (AH-104b-del/c). Both are set by the erasure engine, and by
 * nothing else.
 *
 * §5.40: additive-nullable only. No default, no backfill, no index; zero
 * existing rows are read or written by this migration, so every account that
 * exists today reads as "not erased". Nothing is erased by deploying this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('erased_at')->nullable();
        });

        Schema::table('creators', function (Blueprint $table): void {
            $table->timestamp('erased_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->dropColumn('erased_at');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('erased_at');
        });
    }
};
