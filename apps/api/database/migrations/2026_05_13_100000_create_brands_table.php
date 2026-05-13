<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `brands` table — a first-class Phase 1 entity owned by
 * an agency. See docs/03-DATA-MODEL.md §4 for the authoritative schema.
 *
 * Honest deviation #D1: the data-model spec does not include a `status`
 * column on brands. Added here per the Sprint 2 kickoff's pre-answered
 * design decision ("status field, not soft delete") following the
 * campaign entity precedent (status + deleted_at). Category:
 * structurally-correct minimal extension.
 *
 * P2 reserved columns (nullable/defaulted so Phase 2 doesn't require
 * an expand/migrate/contract cycle on a hot table):
 *   - exclusivity_window_days   — nullable integer; Phase 2 activates UI
 *   - client_portal_enabled     — boolean, default false; Phase 2 feature flag
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            // Tenant scope — every brand belongs to exactly one agency.
            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')
                ->references('id')
                ->on('agencies')
                ->restrictOnDelete();

            $table->string('name', 160);

            // slug is unique WITHIN an agency (composite unique index below).
            $table->string('slug', 64);

            $table->text('description')->nullable();
            $table->string('industry', 64)->nullable();
            $table->string('website_url', 2048)->nullable();
            $table->string('logo_path', 512)->nullable();

            $table->char('default_currency', 3)->default('EUR');
            $table->char('default_language', 2)->default('en');

            // Sprint 2 operational status (deviation #D1 — see docblock).
            $table->string('status', 16)->default('active');

            // Structured brand-safety rules: topics to avoid, content
            // guidelines. Laravel json() maps to jsonb on Postgres.
            $table->json('brand_safety_rules')->nullable();

            // P2 reserved columns — present from P1 with safe defaults so
            // Phase 2 activation avoids expand/migrate/contract on this table.
            $table->integer('exclusivity_window_days')->nullable();
            $table->boolean('client_portal_enabled')->default(false);

            $table->timestamps();
            $table->softDeletes();
        });

        // Per docs/03-DATA-MODEL.md §4 indexes.
        Schema::table('brands', function (Blueprint $table): void {
            $table->unique(['agency_id', 'slug'], 'unique_brands_agency_slug');
            $table->index('agency_id', 'idx_brands_agency_id');
            $table->index('status', 'idx_brands_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
