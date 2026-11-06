<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Agencies\Models\AgencyCreatorRelation;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Features\MessagesV2InboxEnabled;
use App\Modules\Messaging\Services\MessageInboxQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * AH-119 A2 — `GET agencies/{agency}/message-inbox`: the unified agency inbox (direct and campaign
 * conversations in one list). Any active member may read (staff included). Behind
 * `messages_v2_inbox_enabled`: 404 `not_found` while off. See {@see MessageInboxQuery}.
 */
final class AgencyMessageInboxController
{
    public function __construct(private readonly MessageInboxQuery $inbox) {}

    public function index(Request $request, Agency $agency): JsonResponse
    {
        if (! Feature::active(MessagesV2InboxEnabled::NAME)) {
            return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
        }

        Gate::authorize('viewAny', AgencyCreatorRelation::class);

        $params = $request->validate([
            'brand' => ['sometimes', 'array', 'max:50'],
            'brand.*' => ['string', 'max:40'],
            'campaign' => ['sometimes', 'array', 'max:50'],
            'campaign.*' => ['string', 'max:40'],
            'unread' => ['sometimes', 'boolean'],
            'owner' => ['sometimes', 'in:me,all'],
            // AH-119 walk: only the Direct (relationship) threads.
            'direct' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:300'],
            // Reserved for Stage B (message search); accepted and ignored in Stage A.
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        /** @var User $viewer */
        $viewer = $request->user();
        $result = $this->inbox->run($agency, $viewer, $params);

        return response()->json(['data' => $result['rows'], 'meta' => $result['meta']]);
    }
}
