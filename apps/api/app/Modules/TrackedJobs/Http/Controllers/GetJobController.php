<?php

declare(strict_types=1);

namespace App\Modules\TrackedJobs\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Models\User;
use App\Modules\TrackedJobs\Http\Resources\TrackedJobResource;
use App\Modules\TrackedJobs\Models\TrackedJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/jobs/{job}
 *
 * Returns the tracked-job state. Authorization is two-tiered:
 *
 *   1. The authenticated user is the job's initiator → allowed.
 *   2. The job carries an agency_id and the authenticated user is an
 *      active member of that agency → allowed.
 *   3. Otherwise → 404 (not 403 — the job's existence is itself
 *      sensitive; an attacker should not be able to enumerate jobs
 *      belonging to other tenants by guessing ULIDs).
 *
 * Generic-404-on-unauthorised is standing standard #42 (no enumerable
 * identifiers) applied to async-job state.
 */
final class GetJobController
{
    public function __invoke(Request $request, string $job): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tracked = TrackedJob::query()
            ->where('ulid', $job)
            ->first();

        if ($tracked === null || ! $this->isAuthorized($user, $tracked)) {
            return ErrorResponse::single(
                $request,
                404,
                'job.not_found',
                'Job not found.',
            );
        }

        return (new TrackedJobResource($tracked))->response();
    }

    private function isAuthorized(User $user, TrackedJob $job): bool
    {
        if ($job->initiator_user_id === $user->id) {
            return true;
        }

        if ($job->agency_id === null) {
            return false;
        }

        return AgencyMembership::withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('agency_id', $job->agency_id)
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->whereNull('deleted_at')
            ->exists();
    }
}
