<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Adds `processing_status` to `creator_portfolio_items` (ad-hoc AH-004 Q2).
 *
 * Large images now upload via the presigned-PUT path and are sanitized
 * asynchronously (EXIF strip + thumbnail) by ProcessPortfolioImageJob. The
 * column tracks that lifecycle: processing | ready | failed.
 *
 * Backfill is implicit and safe: the column is added NOT NULL DEFAULT 'ready',
 * so every existing row (all already-final links / videos / small images)
 * becomes `ready`. Only newly-uploaded large images are created `processing`
 * and flipped by the worker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creator_portfolio_items', function (Blueprint $table): void {
            $table->string('processing_status', 16)
                ->default('ready')
                ->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('creator_portfolio_items', function (Blueprint $table): void {
            $table->dropColumn('processing_status');
        });
    }
};
