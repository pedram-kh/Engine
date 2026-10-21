<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * AH-104b-del A1 — the deletion REQUEST, stored on the user.
 *
 * `deletion_requested_at` is when the creator asked; `deletion_scheduled_for` is
 * the date the grace ends (`requested_at + 14 days`, computed once at request time
 * and stored, so a later change of the grace length never alters a request already
 * made). Both are null for every account that exists today, and both go back to
 * null when the creator cancels. AH-104c reads `deletion_scheduled_for` for its
 * "Ready to erase" view; the erasure engine keeps both on an erased account.
 *
 * §5.40: additive-nullable only. No default, no backfill. The one index serves the
 * AH-104c "ready" query and is built on a table of a few thousand rows. Nothing is
 * requested, hidden or erased by deploying this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamp('deletion_scheduled_for')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['deletion_scheduled_for']);
            $table->dropColumn(['deletion_requested_at', 'deletion_scheduled_for']);
        });
    }
};
