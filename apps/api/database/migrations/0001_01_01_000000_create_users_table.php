<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| users — Phase 1 identity table
|--------------------------------------------------------------------------
|
| Authoritative schema is docs/03-DATA-MODEL.md §2. Every authenticatable
| principal (creator, agency staff, brand client in Phase 2, platform
| admin) is a row in this table; module-specific data hangs off via
| 1:1 satellite tables (admin_profiles in Sprint 1; creators / brand_users
| in later sprints).
|
| Sessions and password_reset_tokens live in their own migration files
| per docs/02-CONVENTIONS.md §2.13 ("Every migration is a separate file").
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('email', 320)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('remember_token', 100)->nullable();

            // type: creator | agency_user | brand_user (P2) | platform_admin
            // See App\Modules\Identity\Enums\UserType.
            $table->string('type', 32)->index();

            $table->string('name', 160);

            // Locale + display preferences. See docs/00-MASTER-ARCHITECTURE.md §13.
            $table->char('preferred_language', 2)->default('en');
            $table->char('preferred_currency', 3)->default('EUR');
            $table->string('timezone', 64)->default('UTC');
            // light | dark | system — see App\Modules\Identity\Enums\ThemePreference
            $table->string('theme_preference', 8)->default('system');

            $table->timestamp('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();

            // 2FA fields. Encrypted at the application layer via Laravel's
            // `encrypted` cast on the User model, per docs/03-DATA-MODEL.md §23
            // and docs/05-SECURITY-COMPLIANCE.md §4.3.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->boolean('mfa_required')->default(false);

            $table->boolean('is_suspended')->default(false);
            $table->text('suspended_reason')->nullable();
            $table->timestamp('suspended_at')->nullable();

            $table->timestamps();
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
