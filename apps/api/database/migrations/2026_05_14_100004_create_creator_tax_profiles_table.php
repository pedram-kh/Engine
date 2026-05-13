<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `creator_tax_profiles` table per docs/03-DATA-MODEL.md §5.
 * Sprint 3 Chunk 1 migration #11.
 *
 * One row per creator (creator_id is unique). Filled at wizard Step 6.
 *
 * Encryption (#23 of data-model spec, applied via casts on the model):
 *   - legal_name (text type to accommodate ciphertext envelope)
 *   - tax_id     (text type, same reason)
 *   - address    (text type — JSON struct stored as encrypted blob)
 *
 * The schema-level types are intentionally `text` rather than `varchar(255)`
 * because Laravel's `encrypted` cast wraps the plaintext in a JSON envelope
 * with the cipher, MAC, and IV — the encrypted-string size is roughly
 * 4-5× the plaintext length plus a fixed envelope overhead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_tax_profiles', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('creator_id')->unique();
            $table->foreign('creator_id')
                ->references('id')
                ->on('creators')
                ->cascadeOnDelete();

            // All three encrypted at the application layer. text types
            // accommodate the ciphertext envelope.
            $table->text('legal_name');
            $table->string('tax_form_type', 16);
            $table->text('tax_id');
            $table->char('tax_id_country', 2);
            $table->text('address');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->foreign('verified_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_tax_profiles');
    }
};
