<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Enums\AgencyRole;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Creators\Jobs\BulkCreatorInvitationJob;
use App\Modules\Creators\Services\BulkInviteCsvParser;
use App\Modules\Identity\Models\User;
use App\Modules\TrackedJobs\Models\TrackedJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * POST /api/v1/agencies/{agency}/creators/invitations/bulk
 *
 *   - Multipart upload of a CSV (key: file).
 *   - Parses the CSV synchronously (validation only).
 *   - Persists a TrackedJob and dispatches BulkCreatorInvitationJob.
 *   - Returns 202 Accepted with the TrackedJob ulid + parse meta.
 *
 * Response shape (D-pause-9 + Q3 confirmation):
 *
 *   {
 *     "data": { "id": "01HX...", "type": "bulk_creator_invitation" },
 *     "meta": {
 *       "row_count": 247,
 *       "exceeds_soft_warning": true,
 *       "errors": [{ "row": 13, "code": "invitation.email_invalid", ... }]
 *     },
 *     "links": { "self": "/api/v1/jobs/01HX..." }
 *   }
 *
 * Authorization: in-controller authorizeAdmin() per D-pause-9 (mirrors
 * Sprint 2's InvitationController pattern).
 */
final class BulkInviteController
{
    public function __construct(
        private readonly BulkInviteCsvParser $parser,
    ) {}

    public function store(Request $request, Agency $agency): JsonResponse
    {
        $this->authorizeAdmin($request, $agency);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        /** @var UploadedFile|array<int, UploadedFile>|null $file */
        $file = $request->file('file');
        if ($file === null || is_array($file)) {
            return ErrorResponse::single($request, 422, 'bulk_invite.missing_file', 'Missing or invalid file upload.');
        }

        try {
            $parsed = $this->parser->parse($file);
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, 422, 'bulk_invite.parse_failed', $e->getMessage());
        }

        if ($parsed['row_count'] === 0) {
            return ErrorResponse::single($request, 422, 'bulk_invite.empty', 'CSV contains no valid rows.');
        }

        /** @var User $inviter */
        $inviter = $request->user();

        $tracked = TrackedJob::create([
            'kind' => 'bulk_creator_invitation',
            'initiator_user_id' => $inviter->id,
            'agency_id' => $agency->id,
        ]);

        BulkCreatorInvitationJob::dispatch(
            trackedJobId: $tracked->id,
            agencyId: $agency->id,
            inviterUserId: $inviter->id,
            emails: array_map(fn (array $row): string => $row['email'], $parsed['rows']),
        );

        return response()->json([
            'data' => [
                'id' => $tracked->ulid,
                'type' => 'bulk_creator_invitation',
            ],
            'meta' => [
                'row_count' => $parsed['row_count'],
                'exceeds_soft_warning' => $parsed['exceeds_soft_warning'],
                'errors' => $parsed['errors'],
            ],
            'links' => [
                'self' => '/api/v1/jobs/'.$tracked->ulid,
            ],
        ], 202);
    }

    /**
     * D-pause-9: in-controller authorizeAdmin() pattern (mirrors
     * Sprint 2 InvitationController). Independent unit-test (#40)
     * verifies the role check fails when omitted.
     */
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
            abort(403, 'Only agency admins can bulk-invite creators.');
        }
    }
}
