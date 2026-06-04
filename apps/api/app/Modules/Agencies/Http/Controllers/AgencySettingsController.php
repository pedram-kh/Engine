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

        $validated = $request->validated();

        $before = $this->settingsSnapshot($agency);

        // default_currency / default_language are top-level columns;
        // blacklist_notification_policy (Sprint 7) lives INSIDE the settings
        // jsonb, so it is merged rather than mass-assigned as a column.
        $columnUpdates = array_intersect_key($validated, array_flip(['default_currency', 'default_language']));
        if ($columnUpdates !== []) {
            $agency->fill($columnUpdates);
        }

        if (array_key_exists('blacklist_notification_policy', $validated)) {
            $settings = $agency->settings ?? [];
            $settings['blacklist_notification_policy'] = (bool) $validated['blacklist_notification_policy'];
            $agency->settings = $settings;
        }

        $agency->save();

        $after = $this->settingsSnapshot($agency);

        Audit::log(
            action: AuditAction::AgencySettingsUpdated,
            subject: $agency,
            before: $before,
            after: $after,
            agencyId: $agency->id,
        );

        return new AgencySettingsResource($agency->fresh() ?? $agency);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsSnapshot(Agency $agency): array
    {
        return [
            'default_currency' => $agency->default_currency,
            'default_language' => $agency->default_language,
            'blacklist_notification_policy' => (bool) ($agency->settings['blacklist_notification_policy'] ?? false),
        ];
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
