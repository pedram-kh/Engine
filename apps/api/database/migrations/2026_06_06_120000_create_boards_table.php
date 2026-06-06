<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `boards` table — one board per campaign (Sprint 12 Chunk 1, D-1).
 * Per docs/03-DATA-MODEL.md §10.
 *
 * Tenancy (D-2): tenant-scoped via `agency_id` (the model uses BelongsToAgency,
 * mirroring message_threads). The board inherits the campaign's agency; the
 * `agency_id` is denormalized so directly-addressable board queries carry the
 * global scope. Columns + cards carry their own denormalized `agency_id` too;
 * automations + movements scope transitively through `board_id`.
 *
 * The `campaign_id` UNIQUE enforces the 1:1 board↔campaign and backs the
 * firstOrCreate idempotency of the lazy board-GET heal (D-4) — no backfill
 * migration is needed.
 *
 * FK delete rules: `agency_id` RESTRICT (the tenancy anchor, mirroring
 * message_threads); `campaign_id` CASCADE (a board cannot outlive its campaign).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            // Denormalized tenant scope (BelongsToAgency), RESTRICT.
            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')
                ->references('id')
                ->on('agencies')
                ->restrictOnDelete();

            // One board per campaign — the UNIQUE backs firstOrCreate idempotency
            // of the lazy GET heal (D-4).
            $table->unsignedBigInteger('campaign_id');
            $table->foreign('campaign_id')
                ->references('id')
                ->on('campaigns')
                ->cascadeOnDelete();
            $table->unique('campaign_id', 'unique_boards_campaign');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boards');
    }
};
