<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| users.two_factor_enrollment_suspended_at — chunk 5 (TOTP 2FA)
|--------------------------------------------------------------------------
|
| When a user racks up 10 invalid TOTP/recovery code attempts within a
| sliding 15-minute window the verification path freezes their 2FA
| enrollment until an admin reviews and clears it. The state lives on
| this column so admins can list "stuck" users in a single query and so
| the verifier can fail closed without consulting the cache (which can
| evict).
|
| Sprint 8 (Postgres-CI) will rehome the column under the same expand /
| migrate / contract pattern as the other type upgrades documented in
| docs/tech-debt.md.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('two_factor_enrollment_suspended_at')
                ->nullable()
                ->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('two_factor_enrollment_suspended_at');
        });
    }
};
