<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
||--------------------------------------------------------------------------
|| users.mfa_exempted_at + users.mfa_exemption_reason — AH-087
||--------------------------------------------------------------------------
||
|| Per-user 2FA exemption, set by a platform admin from the agency-members
|| drill-in. An exempted agency member passes the MFA-gated admin-sensitive
|| surfaces without enrolling; the exemption is visible on the member row
|| and both directions of the flip are audited with a mandatory reason.
||
|| WHY A NEW PAIR RATHER THAN users.mfa_required (AH-087 plan-pause, R1):
|| `mfa_required` is a two-state column that cannot distinguish "never
|| required" from "deliberately exempted", and it already defaults to
|| false for every agency user (every agency-user creation path writes
|| false explicitly). Branching enforcement on `mfa_required === false`
|| would therefore have exempted the entire live agency population on
|| deploy — the inverse of the inertness requirement. Making it usable
|| would need a population-wide production data write purely to render a
|| new branch inert, which §5.40 rules out. `mfa_required` is left alone
|| with its documented meaning (docs/03-DATA-MODEL.md §2).
||
|| This pair mirrors the suspend pattern's own shape exactly
|| (agencies.suspended_at + suspended_reason): a nullable timestamp that IS
|| the state, plus the operator-visible reason rendered on the surface. NULL
|| means not exempt, so inertness is structural — every live row is NULL at
|| deploy, no backfill, nothing written by this migration (§5.40:
|| schema-only, no data writes, ever).
||
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('mfa_exempted_at')
                ->nullable()
                ->after('mfa_required');

            $table->text('mfa_exemption_reason')
                ->nullable()
                ->after('mfa_exempted_at');
        });
    }

    /**
     * Honest inverse (§5.40): both columns are net-new here and hold no
     * pre-existing data, so dropping them restores the prior schema
     * exactly. What it CANNOT restore is exemptions granted after this
     * migration ran — rolling back discards them, which re-imposes the
     * MFA gate on anyone currently exempt (fail-closed, not fail-open).
     * The audit trail (user.mfa_exempted / user.mfa_exemption_removed)
     * survives in audit_logs regardless.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['mfa_exempted_at', 'mfa_exemption_reason']);
        });
    }
};
