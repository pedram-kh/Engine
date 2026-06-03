<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen creator_availability_blocks.kind from varchar(16) to varchar(32).
 *
 * The original column (2026_05_14_100003) was sized at 16, but the longest
 * Kind enum value — `exclusive_contract` (18 chars) — does not fit. Postgres
 * enforces the length and rejects the insert ("value too long for type
 * character varying(16)"), so creating an exclusive-contract availability
 * block 500s in production. SQLite (the test/CI driver) does NOT enforce
 * varchar length, which is why every kind-related test passed and the bug
 * shipped — the same SQLite/Postgres divergence class logged in tech-debt.
 *
 * 32 gives headroom for any future kind without re-touching the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creator_availability_blocks', function (Blueprint $table): void {
            $table->string('kind', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('creator_availability_blocks', function (Blueprint $table): void {
            $table->string('kind', 16)->change();
        });
    }
};
