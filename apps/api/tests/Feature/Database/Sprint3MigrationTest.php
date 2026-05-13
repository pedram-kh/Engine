<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 3 Chunk 1 — column-set migration test
|--------------------------------------------------------------------------
|
| Mirrors Sprint1MigrationTest's discipline: assert that every column from
| docs/03-DATA-MODEL.md §5 + §6 is present after migration:fresh.
|
| Reverse-migration coverage lives implicitly in Pest's RefreshDatabase
| trait which runs migrate:fresh between tests; explicit rollback is
| exercised by docs/08-DATABASE-EVOLUTION.md §7.2 in CI via `php artisan
| migrate:rollback` + re-migrate. Operating environment for both is
| Postgres in CI and SQLite locally.
|
*/

it('creators table has every Phase 1 column from docs/03-DATA-MODEL.md §5', function (): void {
    expect(Schema::hasTable('creators'))->toBeTrue();

    $expected = [
        'id',
        'ulid',
        'user_id',
        'display_name',
        'bio',
        'country_code',
        'region',
        'primary_language',
        'secondary_languages',
        'avatar_path',
        'cover_path',
        'categories',
        'verification_level',
        'tier',
        'application_status',
        'approved_at',
        'approved_by_user_id',
        'rejected_at',
        'rejection_reason',
        'profile_completeness_score',
        'last_active_at',
        'signed_master_contract_id',
        'kyc_status',
        'kyc_verified_at',
        'tax_profile_complete',
        'payout_method_set',
        'submitted_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('creators', $column))
            ->toBeTrue("creators.{$column} should exist");
    }
});

it('creator_social_accounts table has every Phase 1 column', function (): void {
    expect(Schema::hasTable('creator_social_accounts'))->toBeTrue();

    $expected = [
        'id', 'ulid', 'creator_id', 'platform', 'platform_user_id',
        'handle', 'profile_url', 'oauth_access_token', 'oauth_refresh_token',
        'oauth_expires_at', 'last_synced_at', 'sync_status',
        'metrics', 'audience_demographics', 'is_primary',
        'created_at', 'updated_at', 'deleted_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('creator_social_accounts', $column))
            ->toBeTrue("creator_social_accounts.{$column} should exist");
    }
});

it('creator_portfolio_items table has every Phase 1 column', function (): void {
    expect(Schema::hasTable('creator_portfolio_items'))->toBeTrue();

    $expected = [
        'id', 'ulid', 'creator_id', 'kind', 'title', 'description',
        's3_path', 'external_url', 'thumbnail_path', 'mime_type',
        'size_bytes', 'duration_seconds', 'position',
        'created_at', 'updated_at', 'deleted_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('creator_portfolio_items', $column))
            ->toBeTrue("creator_portfolio_items.{$column} should exist");
    }
});

it('creator_availability_blocks table has every Phase 1 column', function (): void {
    expect(Schema::hasTable('creator_availability_blocks'))->toBeTrue();

    $expected = [
        'id', 'ulid', 'creator_id', 'starts_at', 'ends_at', 'is_all_day',
        'kind', 'block_type', 'reason', 'assignment_id',
        'is_recurring', 'recurrence_rule', 'external_calendar_id', 'external_event_id',
        'created_at', 'updated_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('creator_availability_blocks', $column))
            ->toBeTrue("creator_availability_blocks.{$column} should exist");
    }
});

it('creator_tax_profiles table has every Phase 1 column', function (): void {
    expect(Schema::hasTable('creator_tax_profiles'))->toBeTrue();

    $expected = [
        'id', 'ulid', 'creator_id', 'legal_name', 'tax_form_type',
        'tax_id', 'tax_id_country', 'address',
        'submitted_at', 'verified_at', 'verified_by_user_id',
        'created_at', 'updated_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('creator_tax_profiles', $column))
            ->toBeTrue("creator_tax_profiles.{$column} should exist");
    }
});

it('creator_payout_methods table has every Phase 1 column', function (): void {
    expect(Schema::hasTable('creator_payout_methods'))->toBeTrue();

    $expected = [
        'id', 'ulid', 'creator_id', 'provider', 'provider_account_id',
        'currency', 'is_default', 'status', 'verified_at',
        'created_at', 'updated_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('creator_payout_methods', $column))
            ->toBeTrue("creator_payout_methods.{$column} should exist");
    }
});

it('creator_kyc_verifications table has every Phase 1 column', function (): void {
    expect(Schema::hasTable('creator_kyc_verifications'))->toBeTrue();

    $expected = [
        'id', 'ulid', 'creator_id', 'provider', 'provider_session_id',
        'provider_decision_id', 'status', 'decision_data', 'failure_reason',
        'started_at', 'completed_at', 'expires_at',
        'created_at', 'updated_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('creator_kyc_verifications', $column))
            ->toBeTrue("creator_kyc_verifications.{$column} should exist");
    }
});

it('agency_creator_relations table has every Phase 1 column from §6 plus the Sprint 3 invitation columns', function (): void {
    expect(Schema::hasTable('agency_creator_relations'))->toBeTrue();

    $expected = [
        // Spec §6 columns.
        'id', 'ulid', 'agency_id', 'creator_id', 'relationship_status',
        'is_blacklisted', 'blacklist_scope', 'blacklist_reason', 'blacklist_type',
        'blacklisted_at', 'blacklisted_by_user_id', 'notification_sent_at',
        'appeal_status', 'appeal_submitted_at',
        'internal_rating', 'internal_notes',
        'total_campaigns_completed', 'total_paid_minor_units', 'last_engaged_at',
        // Sprint 3 invitation columns (kickoff §1.1).
        'invitation_token_hash', 'invitation_expires_at', 'invitation_sent_at', 'invited_by_user_id',
        'created_at', 'updated_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('agency_creator_relations', $column))
            ->toBeTrue("agency_creator_relations.{$column} should exist");
    }
});

it('all Sprint 3 Chunk 1 tables exist after migrate:fresh', function (): void {
    $expected = [
        'creators',
        'creator_social_accounts',
        'creator_portfolio_items',
        'creator_availability_blocks',
        'creator_tax_profiles',
        'creator_payout_methods',
        'creator_kyc_verifications',
        'agency_creator_relations',
    ];

    foreach ($expected as $table) {
        expect(Schema::hasTable($table))->toBeTrue("table {$table} should exist");
    }
});
