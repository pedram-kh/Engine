<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `contracts` table per docs/03-DATA-MODEL.md §8 (`:570–597`).
 * Sprint 4 Chunk 4 (D-c4-1). This is the spec'd, vendor-oriented contract
 * record. The flag-OFF click-through accept (CreatorWizardService) populates
 * a subset (master_universal / signed / internal); the future e-sign vendor
 * adapter fills the envelope columns (signature_envelope_id, sent_at, …)
 * — it EXTENDS this table, it does not rebuild it.
 *
 * Shape is the full spec table (not the subset the kickoff named) — the
 * polymorphic subject + envelope columns ship now so the vendor chunk
 * inherits them rather than re-migrating.
 *
 * Deferred FKs (target tables not yet shipped):
 *   - `template_id` → `contract_templates.id`. The contract_templates
 *     table is spec'd (§8) but unbuilt; the column ships nullable WITHOUT
 *     an FK constraint, mirroring how `creators.signed_master_contract_id`
 *     itself shipped FK-less ahead of this table. A later chunk adds the
 *     constraint once contract_templates exists.
 *
 * NOTE — `creators.signed_master_contract_id` FK is intentionally NOT added
 * in this chunk. That column is currently multi-meaning (three writers stuff
 * incompatible values into it — see docs/internal/tech-debt.md "Deferred
 * contracts FK"); adding the DB-level constraint first requires the vendor
 * envelope chunk to convert the two sentinel writers to real `contracts`
 * rows. The click-through path (this chunk) writes a REAL contracts.id.
 *
 * `signed_signature_data` deviates from the spec's `jsonb` to `text` so the
 * Eloquent `encrypted:array` cast can wrap the IP/UA evidence at rest
 * (docs/05-SECURITY-COMPLIANCE.md §4), mirroring `creator_tax_profiles.address`.
 * The encrypted blob is not valid jsonb; continuity queries read the
 * dedicated integer `version` column, not this field, so encryption is free
 * of continuity cost.
 *
 * Forward + backward tested per docs/08-DATABASE-EVOLUTION.md §7.1 + §7.2.
 * Runs on Postgres (CI) and SQLite (local) — no driver-specific types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            // Null for global Engine C T&Cs (the master_universal
            // click-through case); set for agency-scoped agreements.
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->foreign('agency_id')
                ->references('id')
                ->on('agencies')
                ->restrictOnDelete();

            // master_universal | master_agency | per_campaign
            $table->string('kind', 16);

            // Polymorphic subject: `creator` (master) or
            // `campaign_assignment` (addendum). No FK — the type column
            // disambiguates the target table.
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');

            // Deferred FK — contract_templates is unbuilt (see docblock).
            $table->unsignedBigInteger('template_id')->nullable();

            $table->integer('version');
            $table->string('title', 255);
            $table->text('body_markdown');
            $table->string('body_pdf_path', 512)->nullable();

            // docusign | dropboxsign | internal. `internal` is the
            // click-through acceptance; the vendor adapter stamps its own.
            $table->string('signature_provider', 32)->nullable();
            $table->string('signature_envelope_id', 255)->nullable();

            // draft | sent | signed | declined | expired | superseded
            $table->string('status', 16);

            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('signed_at')->nullable();

            $table->unsignedBigInteger('signed_by_creator_id')->nullable();
            $table->foreign('signed_by_creator_id')
                ->references('id')
                ->on('creators')
                ->nullOnDelete();

            // Encrypted at rest (text, not jsonb — see docblock): holds
            // { method, version, ip, user_agent, accepted_at } for the
            // click-through path; provider IP/UA/timestamp for the vendor path.
            $table->text('signed_signature_data')->nullable();

            $table->timestampTz('expires_at')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('contracts', function (Blueprint $table): void {
            $table->index(['subject_type', 'subject_id'], 'idx_contracts_subject');
            $table->index('status', 'idx_contracts_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
