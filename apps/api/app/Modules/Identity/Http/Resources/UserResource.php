<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Creators\Features\AssignmentOfferEditEnabled;
use App\Modules\Creators\Features\CampaignRegionRestrictionEnabled;
use App\Modules\Identity\Features\AccountDeletionEnabled;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Features\MessagesV2CreatorInboxEnabled;
use App\Modules\Messaging\Features\MessagesV2EditSearchEnabled;
use App\Modules\Messaging\Features\MessagesV2InboxEnabled;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Pennant\Feature;

/**
 * Public projection of a {@see User}, shaped per docs/04-API-DESIGN.md §7.
 *
 * Sensitive columns (password, two_factor_*, remember_token) are
 * structurally absent — they are not in `$fillable` and not in this
 * resource. The model's `$hidden` is the secondary defense; the absence
 * of any code path that selects them is the primary defense.
 */
final class UserResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->ulid,
            'type' => 'user',
            'attributes' => [
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'name' => $user->name,
                'last_name' => $user->last_name,
                'user_type' => $user->type->value,
                'preferred_language' => $user->preferred_language,
                'preferred_currency' => $user->preferred_currency,
                'timezone' => $user->timezone,
                'theme_preference' => $user->theme_preference->value,
                'mfa_required' => $user->mfa_required,
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                // AH-087 — a BOOLEAN projection only: the SPA's MFA-policy
                // guard needs to know whether this user is exempt, never when
                // or why. The timestamp and the admin's reason stay on the
                // admin members surface.
                'mfa_exempt' => $user->isMfaExempt(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at->toIso8601String(),
                // AH-104b-del: the first menu-level flag. The Security entry and
                // its route read this, never a separate request. Only a creator
                // can ask for deletion, so everyone else is `false` without
                // evaluating the flag at all. A gate for the UI only: the API
                // enforces the flag itself (404), so a stale `true` is harmless.
                'features' => [
                    'account_deletion' => $user->isCreator() && Feature::active(AccountDeletionEnabled::NAME),
                    // AH-118 — agency-side switches. UI gates only (the API enforces
                    // each flag itself); `false` without evaluating the flag for a
                    // creator or a platform admin, the `account_deletion` shape.
                    'campaign_region_restriction' => $this->isAgencySide($user) && Feature::active(CampaignRegionRestrictionEnabled::NAME),
                    'assignment_offer_edit' => $this->isAgencySide($user) && Feature::active(AssignmentOfferEditEnabled::NAME),
                    // AH-119 — the unified inbox. UI gate only; the API enforces the flag (404).
                    'messages_v2_inbox' => $this->isAgencySide($user) && Feature::active(MessagesV2InboxEnabled::NAME),
                    // AH-119 Stage B — edit, delete and search. Agency users AND creators (both sides use the edit
                    // and delete routes); `false` without evaluating the flag for a platform admin. UI gate only.
                    // AH-115 L4 — the creator's unified inbox. Creators only; `false` without evaluating the flag
                    // for anyone else. UI gate only; the API enforces the flag (404).
                    'messages_v2_creator_inbox' => $user->isCreator() && Feature::active(MessagesV2CreatorInboxEnabled::NAME),
                    'messages_v2_edit_search' => ! $user->isPlatformAdmin() && Feature::active(MessagesV2EditSearchEnabled::NAME),
                ],
            ],
            'relationships' => [
                'agency_memberships' => [
                    'data' => $this->agencyMembershipsData($user),
                ],
            ],
        ];
    }

    /**
     * An agency user: neither a creator nor a platform admin. The AH-118 menu
     * flags are evaluated for this audience only.
     */
    private function isAgencySide(User $user): bool
    {
        return ! $user->isCreator() && ! $user->isPlatformAdmin();
    }

    /**
     * Eager-loads accepted, non-deleted agency memberships for the user.
     *
     * Chunk 2 adds this so the SPA can populate the workspace switcher and
     * derive the current agency context without an extra API round-trip.
     *
     * @return list<array{agency_id: string, agency_name: string, role: string}>
     */
    private function agencyMembershipsData(User $user): array
    {
        $rows = AgencyMembership::query()
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->whereNull('deleted_at')
            ->with('agency')
            ->get();

        $result = [];

        foreach ($rows as $m) {
            $agency = $m->agency;

            if ($agency === null) {
                continue;
            }

            $result[] = [
                'agency_id' => $agency->ulid,
                'agency_name' => $agency->name,
                'role' => $m->role->value,
            ];
        }

        return $result;
    }
}
