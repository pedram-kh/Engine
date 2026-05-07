<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('users table has every Phase 1 column from docs/03-DATA-MODEL.md §2', function (): void {
    expect(Schema::hasTable('users'))->toBeTrue();

    $expected = [
        'id',
        'ulid',
        'email',
        'email_verified_at',
        'password',
        'remember_token',
        'type',
        'name',
        'preferred_language',
        'preferred_currency',
        'timezone',
        'theme_preference',
        'last_login_at',
        'last_login_ip',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'mfa_required',
        'is_suspended',
        'suspended_reason',
        'suspended_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('users', $column))
            ->toBeTrue("users.{$column} should exist");
    }
});

it('agencies table has every Phase 1 column from docs/03-DATA-MODEL.md §3', function (): void {
    expect(Schema::hasTable('agencies'))->toBeTrue();

    $expected = [
        'id', 'ulid', 'name', 'slug', 'country_code',
        'default_currency', 'default_language',
        'logo_path', 'primary_color',
        'subscription_tier', 'subscription_status',
        'billing_email', 'tax_id', 'tax_id_country',
        'address', 'settings', 'is_active',
        'created_at', 'updated_at', 'deleted_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('agencies', $column))
            ->toBeTrue("agencies.{$column} should exist");
    }
});

it('agency_users table has every Phase 1 column', function (): void {
    expect(Schema::hasTable('agency_users'))->toBeTrue();

    $expected = [
        'id', 'agency_id', 'user_id', 'role',
        'invited_by_user_id', 'invited_at', 'accepted_at',
        'created_at', 'updated_at', 'deleted_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('agency_users', $column))
            ->toBeTrue("agency_users.{$column} should exist");
    }
});

it('admin_profiles table has every Phase 1 column', function (): void {
    expect(Schema::hasTable('admin_profiles'))->toBeTrue();

    $expected = [
        'id', 'user_id', 'admin_role', 'ip_allowlist',
        'created_at', 'updated_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('admin_profiles', $column))
            ->toBeTrue("admin_profiles.{$column} should exist");
    }
});
