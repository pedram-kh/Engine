<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft link attachments (draft-composer facelift). A draft may carry external
 * reference links (url + optional display name) alongside its presigned media
 * — the same file-or-link pair the relationship-messaging composer offers.
 * Stored as a JSON list: `[{"url": "...", "name": "..."|null}, …]`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_drafts', function (Blueprint $table): void {
            $table->jsonb('links')->nullable()->after('media_attachments');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_drafts', function (Blueprint $table): void {
            $table->dropColumn('links');
        });
    }
};
