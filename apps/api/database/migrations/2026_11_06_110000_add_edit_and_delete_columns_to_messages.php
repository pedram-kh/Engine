<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * AH-119 (Messages v2), B1 — edit and delete of one's own message, on BOTH message tables. Four
 * nullable columns without a default (metadata-only on Postgres: no row is read or rewritten, no
 * backfill, no data command):
 *
 *  - `edited_at`          when the body was last edited; NULL = never edited.
 *  - `original_body`      the text before the FIRST edit (kept; never on any resource or admin surface).
 *  - `deleted_by_user_id` who deleted it (the sender; FK `restrictOnDelete` to match `sender_user_id`).
 *  - `deleted_body`       the text withdrawn by a delete (kept; never on any resource or admin surface).
 *
 * `deleted_at` already exists (SoftDeletes) and stays the deleted marker.
 */
return new class extends Migration
{
    private const TABLES = ['messages', 'relationship_messages'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->timestampTz('edited_at')->nullable();
                $table->text('original_body')->nullable();
                $table->foreignId('deleted_by_user_id')->nullable()
                    ->constrained('users', indexName: "{$name}_deleted_by_user_id_foreign")->restrictOnDelete();
                $table->text('deleted_body')->nullable();
            });
        }
    }

    /**
     * Honest inverse, with one thing it cannot restore: dropping `original_body` and `deleted_body`
     * DISCARDS the text users withdrew (edited-away originals and deleted messages), and `edited_at`
     * and `deleted_by_user_id` are lost with them; deleted rows keep `deleted_at` but their text is
     * gone for good (the body column was nulled when they were deleted). Take a snapshot first if
     * this is ever rolled back against a populated database. Re-running up() restores structure only.
     */
    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('deleted_by_user_id');
            });
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn(['edited_at', 'original_body', 'deleted_body']);
            });
        }
    }
};
