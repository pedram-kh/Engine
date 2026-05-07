<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| agency_users — membership pivot with role
|--------------------------------------------------------------------------
|
| Authoritative schema is docs/03-DATA-MODEL.md §3 ("agency_users"). This
| is richer than a thin pivot — it carries role, invited_by_user_id,
| invited_at, accepted_at — so the application surfaces it as a
| first-class model: App\Modules\Agencies\Models\AgencyMembership.
|
| Unique on (agency_id, user_id): a user can only hold one role per
| agency. A user can belong to multiple agencies (multi-agency Phase 2+
| use case is already supported by the schema; Phase 1 has only one
| agency).
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_users', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('agency_id')
                ->constrained('agencies')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // agency_admin | agency_manager | agency_staff
            // See App\Modules\Agencies\Enums\AgencyRole.
            $table->string('role', 32);

            $table->foreignId('invited_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'user_id'], 'unique_agency_users_agency_user');
            $table->index('user_id', 'idx_agency_users_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_users');
    }
};
