<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @migration-risk low
 *
 * Creates the `creators` table — the global creator entity, per
 * docs/03-DATA-MODEL.md §5. Sprint 3 Chunk 1 migration #7.
 *
 * Tenancy: creators are NOT tenant-scoped. The agency-creator relationship
 * lives on `agency_creator_relations` (migration #14).
 *
 * Wizard-incomplete state: several columns the spec describes as required
 * (display_name, country_code, primary_language, categories) are nullable
 * in the DB so the row can exist between sign-up (CreatorBootstrapService
 * stamps the row with application_status='incomplete') and Step 9 wizard
 * submission. The wizard's submit endpoint enforces non-null on all
 * required fields before flipping application_status to 'pending'.
 *
 * Deferred FK: signed_master_contract_id references `contracts.id`, but
 * the contracts table doesn't ship until Sprint 4 (migration #17). The
 * column is added here without an FK constraint; a follow-up migration
 * in Sprint 4 will add the foreign key once the target table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creators', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->unsignedBigInteger('user_id')->unique();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            // Profile (filled by wizard Step 2; nullable until then).
            $table->string('display_name', 160)->nullable();
            $table->text('bio')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('region', 160)->nullable();
            $table->char('primary_language', 2)->nullable();
            $table->jsonb('secondary_languages')->nullable();
            $table->string('avatar_path', 512)->nullable();
            $table->string('cover_path', 512)->nullable();

            // Category slugs (lifestyle, sports, etc.). Empty array until
            // wizard Step 2; non-empty after submit. jsonb (not json) so
            // the GIN index below can be created.
            $table->jsonb('categories')->nullable();

            // Verification + application lifecycle.
            $table->string('verification_level', 16)->default('unverified');
            $table->string('tier', 16)->nullable();
            $table->string('application_status', 16)->default('incomplete');
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->foreign('approved_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Computed by CompletenessScoreCalculator. Recomputed on every
            // wizard step write inside the same transaction (#5).
            $table->smallInteger('profile_completeness_score')->default(0);

            $table->timestamp('last_active_at')->nullable();

            // Deferred FK to `contracts.id` (Sprint 4 migration #17 adds the
            // FK constraint). Column ships here so Step 8 of the wizard can
            // populate it via the e-sign provider.
            $table->unsignedBigInteger('signed_master_contract_id')->nullable();

            // KYC lifecycle (vendor flow lives in creator_kyc_verifications;
            // these denormalised columns let queries filter without joining).
            $table->string('kyc_status', 16)->default('none');
            $table->timestamp('kyc_verified_at')->nullable();

            $table->boolean('tax_profile_complete')->default(false);
            $table->boolean('payout_method_set')->default(false);

            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('creators', function (Blueprint $table): void {
            $table->index('country_code', 'idx_creators_country_code');
            $table->index('application_status', 'idx_creators_application_status');
            $table->index('verification_level', 'idx_creators_verification_level');
        });

        // Postgres-only GIN index on the categories jsonb column for
        // category containment queries (Sprint 6 creator matching). Skipped
        // on SQLite which is used by some unit-test paths.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE INDEX idx_creators_categories_gin ON creators USING GIN (categories)',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('creators');
    }
};
