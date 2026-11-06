<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Concerns;

use App\Core\Errors\ErrorResponse;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Exceptions\MessageRevisionException;
use App\Modules\Messaging\Http\Resources\MessageResource;
use App\Modules\Messaging\Http\Resources\RelationshipMessageResource;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\Messaging\Services\MessageRevisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AH-119 B2 — the shared tail of the eight edit/delete routes. Each controller resolves its thread
 * (agency binding and gate, or the creator's structural ownership) WITHOUT provisioning one, then hands
 * over here. The message ulid is resolved INSIDE that thread (trashed included): a foreign ulid is 404.
 * The feature flag is enforced by the `RequireMessagesV2EditSearch` route middleware, not here.
 */
trait HandlesMessageRevisions
{
    protected function editMessageResponse(Request $request, MessageThread|RelationshipThread|null $thread, string $messageUlid): JsonResponse
    {
        $message = $thread === null ? null : $this->findRevisableMessage($thread, $messageUlid);
        if ($thread === null || $message === null) {
            return $this->revisionNotFound($request);
        }

        // Lookup (404) comes before validation (422): a foreign ulid never reveals anything.
        $request->merge(['body' => is_string($request->input('body')) ? trim($request->input('body')) : $request->input('body')]);
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:5000']]);

        /** @var User $actor */
        $actor = $request->user();

        return $this->applyRevision($request, fn (MessageRevisionService $service): Message|RelationshipMessage => $service->edit($thread, $message, $actor, (string) $data['body']));
    }

    protected function deleteMessageResponse(Request $request, MessageThread|RelationshipThread|null $thread, string $messageUlid): JsonResponse
    {
        $message = $thread === null ? null : $this->findRevisableMessage($thread, $messageUlid);
        if ($thread === null || $message === null) {
            return $this->revisionNotFound($request);
        }

        /** @var User $actor */
        $actor = $request->user();

        return $this->applyRevision($request, fn (MessageRevisionService $service): Message|RelationshipMessage => $service->delete($thread, $message, $actor));
    }

    private function findRevisableMessage(MessageThread|RelationshipThread $thread, string $messageUlid): Message|RelationshipMessage|null
    {
        if (! $thread->exists) {
            return null;
        }

        $model = $thread instanceof MessageThread ? Message::class : RelationshipMessage::class;

        return $model::query()->withTrashed()->where('thread_id', $thread->id)->where('ulid', $messageUlid)->first();
    }

    /**
     * @param  callable(MessageRevisionService): (Message|RelationshipMessage)  $apply
     */
    private function applyRevision(Request $request, callable $apply): JsonResponse
    {
        try {
            $updated = $apply(app(MessageRevisionService::class));
        } catch (MessageRevisionException $e) {
            return $e->status() === Response::HTTP_NOT_FOUND
                ? $this->revisionNotFound($request)
                : ErrorResponse::single($request, $e->status(), $e->errorCode, $e->getMessage());
        }

        $updated->load('sender:id,name');

        return ($updated instanceof Message ? new MessageResource($updated) : new RelationshipMessageResource($updated))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    private function revisionNotFound(Request $request): JsonResponse
    {
        return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
    }
}
