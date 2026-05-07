<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| admin_profiles — Catalyst Engine ops staff satellite
|--------------------------------------------------------------------------
|
| Authoritative schema is docs/03-DATA-MODEL.md §13. An admin user is a
| User row with type='platform_admin'; admin-specific fields hang off
| this 1:1 satellite. Phase 1 only uses admin_role='super_admin'
| (docs/20-PHASE-1-SPEC.md §4.3); the enum reserves the rest for P2+.
|
| The `ip_allowlist` JSON column is consumed by the admin guard
| middleware in Sprint 1 chunk 7 — when populated, admin SPA requests
| from outside the listed CIDRs are refused.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_profiles', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            // super_admin | support | finance | security
            // See App\Modules\Admin\Enums\AdminRole. Only super_admin in P1.
            $table->string('admin_role', 32);

            $table->json('ip_allowlist')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_profiles');
    }
};
