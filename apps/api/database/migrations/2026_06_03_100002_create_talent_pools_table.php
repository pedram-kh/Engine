<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `talent_pools` table — a saved, per-agency (optionally
 * per-brand) collection of creators (Sprint 6 Chunk 2b, D-2b-1). Per
 * docs/20-PHASE-1-SPEC.md:211 ("Saved talent pools, per agency, per brand").
 *
 * Schema decisions (pinned against the brands / agency_users precedents):
 *   - agency_id          restrictOnDelete — mirrors brands; a pool belongs to
 *                        exactly one agency and an agency with pools cannot be
 *                        hard-deleted out from under them.
 *   - brand_id           nullable, nullOnDelete — brand-scope is a LABEL, not
 *                        an eligibility gate (D-2b-4). If a brand is ever
 *                        hard-deleted the pool survives as agency-wide (its
 *                        label clears) — the only semantics consistent with
 *                        "label" (D-2b-3).
 *   - created_by_user_id nullable, nullOnDelete — the house attribution
 *                        pattern (invited_by / approved_by / blacklisted_by
 *                        all nullOnDelete).
 *   - softDeletes()      a pool holds MEMBERS; an accidental delete must be
 *                        recoverable with its membership intact (D-2b-3). This
 *                        also keeps the CRUD a clean Brand-with-restore mirror.
 *   - unique(agency_id, name) — an agency can't have two pools with the same
 *                        name. NOTE: a soft-deleted pool still occupies this
 *                        unique index (same as brands' slug), so reusing an
 *                        archived pool's name collides until it is restored.
 *
 * No slug column: pools have no public URL; routes resolve by ULID like
 * brands (HasUlid::getRouteKeyName()). No status column: unlike brands
 * (whose status is its own honest-deviation #D1), D-2b-1 specifies none —
 * archive is pure soft-delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_pools', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            // Tenant scope — every pool belongs to exactly one agency.
            $table->unsignedBigInteger('agency_id');
            $table->foreign('agency_id')
                ->references('id')
                ->on('agencies')
                ->restrictOnDelete();

            // Brand-scope is a LABEL (D-2b-4), so it is nullable and clears
            // rather than cascading when a brand is removed (D-2b-3).
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->foreign('brand_id')
                ->references('id')
                ->on('brands')
                ->nullOnDelete();

            $table->string('name', 160);
            $table->text('description')->nullable();

            // House attribution pattern — nullOnDelete (see docblock).
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('talent_pools', function (Blueprint $table): void {
            $table->unique(['agency_id', 'name'], 'unique_talent_pools_agency_name');
            $table->index('agency_id', 'idx_talent_pools_agency_id');
            $table->index('brand_id', 'idx_talent_pools_brand_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_pools');
    }
};
