<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 13 (D-3) — agency suspension.
 *
 * `agencies.is_active` has existed since Sprint 2 but was NEVER read by
 * any code path. Sprint 13 makes agency suspension a real, enforced
 * control: an admin suspends an agency (mandatory reason), which blocks
 * EVERY agency user's login at the auth layer (AuthService::login).
 *
 * Two net-new columns carry the suspension state:
 *   - suspended_at      — the canonical "is this agency suspended?" marker
 *                         (NULL = active). `is_active` is kept in lock-step
 *                         (set false on suspend) for backward-compat with
 *                         any future reader, but `suspended_at` is the SOT.
 *   - suspended_reason  — the mandatory free-text reason captured on
 *                         suspend (cleared on reactivate). Audited via the
 *                         agency.suspended verb's reason field too; stored
 *                         here so the agency-detail surface can show "why".
 *
 * Indexed on suspended_at so the auth-layer login check (resolve the
 * user's primary agency → is it suspended?) and the admin agency list's
 * "suspended" filter stay cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table): void {
            $table->timestamp('suspended_at')->nullable()->after('is_active')->index();
            $table->text('suspended_reason')->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table): void {
            $table->dropIndex(['suspended_at']);
            $table->dropColumn(['suspended_at', 'suspended_reason']);
        });
    }
};
