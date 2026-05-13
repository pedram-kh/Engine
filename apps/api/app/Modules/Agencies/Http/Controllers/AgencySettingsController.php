<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Controllers;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Http\Requests\UpdateAgencySettingsRequest;
use App\Modules\Agencies\Http\Resources\AgencySettingsResource;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;

final class AgencySettingsController
{
    /**
     * GET /api/v1/agencies/{agency}/settings
     *
     * Returns the agency's configurable settings (currency + language).
     * All roles can view settings.
     */
    public function show(Request $request, Agency $agency): AgencySettingsResource
    {
        return new AgencySettingsResource($agency);
    }

    /**
     * PATCH /api/v1/agencies/{agency}/settings
     *
     * Updates agency settings. agency_admin only.
     */
    public function update(UpdateAgencySettingsRequest $request, Agency $agency): AgencySettingsResource
    {
        $this->authorizeAdmin($request, $agency);

        $before = [
            'default_currency' => $agency->default_currency,
            'default_language' => $agency->default_language,
        ];

        $agency->update($request->validated());

        Audit::log(
            action: AuditAction::AgencySettingsUpdated,
            subject: $agency,
            before: $before,
            after: $request->validated(),
            agencyId: $agency->id,
        );

        return new AgencySettingsResource($agency->fresh() ?? $agency);
    }

    private function authorizeAdmin(Request $request, Agency $agency): void
    {
        /** @var User $user */
        $user = $request->user();

        $membership = AgencyMembership::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('agency_id', $agency->id)
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->whereNull('deleted_at')
            ->first();

        if ($membership === null || $membership->role !== AgencyRole::AgencyAdmin) {
            abort(403, 'Only agency admins can update agency settings.');
        }
    }
}
