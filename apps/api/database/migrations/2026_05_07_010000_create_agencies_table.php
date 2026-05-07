<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| agencies — Phase 1 tenant table
|--------------------------------------------------------------------------
|
| Authoritative schema is docs/03-DATA-MODEL.md §3. The agency is the
| top-level tenant; everything tenant-scoped (brands, campaigns,
| assignments, boards, payments, message threads) carries an
| `agency_id` referencing this table. See docs/00-MASTER-ARCHITECTURE.md §4.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('name', 160);
            $table->string('slug', 64)->unique();

            $table->char('country_code', 2);
            $table->char('default_currency', 3)->default('EUR');
            $table->char('default_language', 2)->default('en');

            $table->string('logo_path', 512)->nullable();
            $table->char('primary_color', 7)->nullable();

            // Phase 1 ships pilot only; later phases expand. See
            // docs/03-DATA-MODEL.md §3 — column nullable/defaulted from P1
            // so we never restructure later.
            $table->string('subscription_tier', 32)->default('pilot');
            $table->string('subscription_status', 32)->default('active')->index();

            $table->string('billing_email', 320)->nullable();
            $table->string('tax_id', 64)->nullable();
            $table->char('tax_id_country', 2)->nullable();

            // Structured: line1, line2, city, region, postal, country.
            // Laravel's json() column maps to jsonb on Postgres.
            $table->json('address')->nullable();

            // Tenant-specific runtime config: board defaults, blacklist
            // notification policy, escrow funding moment, etc.
            $table->json('settings');

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agencies');
    }
};
