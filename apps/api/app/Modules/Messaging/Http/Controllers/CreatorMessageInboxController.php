<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Features\MessagesV2CreatorInboxEnabled;
use App\Modules\Messaging\Services\CreatorMessageInboxQuery;
use App\Modules\Messaging\Services\CreatorMessageSearch;
use App\Modules\Messaging\Services\MessageSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * AH-115 L4 — `GET creators/me/message-inbox` and `GET creators/me/message-search?q=&cursor=`: the
 * creator's unified inbox (direct and campaign conversations in one list) and its search. Scoped by the
 * signed-in creator's id (never a path parameter). Behind `messages_v2_creator_inbox_enabled`: 404
 * `not_found` while off. See {@see CreatorMessageInboxQuery} and {@see CreatorMessageSearch}.
 */
final class CreatorMessageInboxController
{
    public function __construct(
        private readonly CreatorMessageInboxQuery $inbox,
        private readonly CreatorMessageSearch $search,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! Feature::active(MessagesV2CreatorInboxEnabled::NAME)) {
            return $this->notFound($request);
        }

        $params = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:300'],
            // AH-115 (walk): the creator's two filters. Additive; absent means "no filter".
            'unread' => ['sometimes', 'boolean'],
            'direct' => ['sometimes', 'boolean'],
        ]);

        /** @var User $viewer */
        $viewer = $request->user();
        $creator = $viewer->creator;
        if ($creator === null) {
            return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'creator.not_found', 'No creator profile is associated with this user.');
        }

        $result = $this->inbox->run($creator, $viewer, $params);

        return response()->json(['data' => $result['rows'], 'meta' => $result['meta']]);
    }

    public function search(Request $request): JsonResponse
    {
        if (! Feature::active(MessagesV2CreatorInboxEnabled::NAME)) {
            return $this->notFound($request);
        }

        $request->merge(['q' => is_string($request->query('q')) ? trim((string) $request->query('q')) : $request->query('q')]);
        $params = $request->validate([
            'q' => ['required', 'string', 'min:'.MessageSearch::MIN_TERM_LENGTH, 'max:200'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        /** @var User $viewer */
        $viewer = $request->user();
        $creator = $viewer->creator;
        if ($creator === null) {
            return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'creator.not_found', 'No creator profile is associated with this user.');
        }

        $result = $this->search->run($creator, $viewer, (string) $params['q'], is_string($params['cursor'] ?? null) ? $params['cursor'] : null);

        return response()->json(['data' => $result['rows'], 'meta' => $result['meta']]);
    }

    private function notFound(Request $request): JsonResponse
    {
        return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
    }
}
