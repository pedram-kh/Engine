<?php

declare(strict_types=1);

use App\Modules\Agencies\Enums\BlacklistType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `brand_creator_blacklists` table — Sprint 7 (A1), the SECOND of
 * the two net-new pieces (the agency-wide blacklist columns shipped on
 * agency_creator_relations in Sprint 3). Per docs/03-DATA-MODEL.md §6.
 *
 * D-2: this table is the SOLE source of truth for brand-scoped blacklists. A
 * brand-scoped blacklist is a row here keyed (brand_id, creator_id) — it does
 * NOT flip is_blacklisted / blacklist_scope='brand' on the relation (no
 * dual-write). The brand → agency link derives through brands.agency_id.
 *
 * Honest deviation from spec §6 (surfaced + approved at plan-pause):
 *   - Adds `ulid` — the universal house pattern (every table carries one).
 *   - Adds soft-deletes — D-3: un-blacklist = soft-delete, preserving history.
 *   - Adds `blacklisted_at` — explicit blacklist timestamp (mirrors the
 *     relation columns) rather than overloading created_at.
 *   - Renames spec's `block_type` → `blacklist_type` (the shared
 *     {@see BlacklistType} enum, consistent with
 *     the relation column) and `created_by_user_id` → `blacklisted_by_user_id`
 *     (consistent with the relation's attribution column).
 *   - `reason` is NOT NULL (D-7: you only ever blacklist WITH a reason).
 *
 * Uniqueness: a PARTIAL unique index on (brand_id, creator_id) WHERE
 * deleted_at IS NULL. With soft-deletes, a plain unique would block
 * re-blacklisting after an un-blacklist (the soft-deleted row keeps the slot);
 * the partial index lets a re-blacklist insert a fresh row while the
 * soft-deleted history rows remain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_creator_blacklists', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('brand_id');
            $table->foreign('brand_id')
                ->references('id')
                ->on('brands')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('creator_id');
            $table->foreign('creator_id')
                ->references('id')
                ->on('creators')
                ->cascadeOnDelete();

            $table->string('blacklist_type', 8);
            // Mandatory (D-7) — you only ever blacklist with a reason.
            $table->text('reason');
            $table->timestamp('blacklisted_at');
            $table->unsignedBigInteger('blacklisted_by_user_id')->nullable();
            $table->foreign('blacklisted_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->timestamp('notification_sent_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('brand_id', 'idx_brand_creator_blacklists_brand_id');
            $table->index('creator_id', 'idx_brand_creator_blacklists_creator_id');
        });

        // Partial unique index — only ACTIVE (not soft-deleted) blacklists are
        // unique per (brand_id, creator_id). Historical soft-deleted rows do
        // not occupy the slot, so a creator can be re-blacklisted for a brand.
        DB::statement(
            'CREATE UNIQUE INDEX unique_brand_creator_blacklist '
            .'ON brand_creator_blacklists (brand_id, creator_id) '
            .'WHERE deleted_at IS NULL',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_creator_blacklists');
    }
};
