<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Exceptions\MessageThreadClosedException;
use App\Modules\Messaging\Http\Controllers\Concerns\HandlesMessageAttachments;
use App\Modules\Messaging\Http\Requests\SendMessageRequest;
use App\Modules\Messaging\Http\Resources\MessageResource;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\MessageAttachmentUploadService;
use App\Modules\Messaging\Services\MessageService;
use App\Modules\Messaging\Services\MessageThreadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * The CREATOR messaging surface (Sprint 11, D-11/D-16) — the creator's single
 * thread per assignment.
 *
 *   GET  /api/v1/creators/me/assignments/{assignment}/messages       thread feed
 *   POST /api/v1/creators/me/assignments/{assignment}/messages       send (creator)
 *   POST /api/v1/creators/me/assignments/{assignment}/messages/read  mark read
 *
 * ⚠ The BelongsToAgency global scope is bypassed deliberately (the documented
 * justified HTTP bypass, mirroring CreatorAssignmentController): the caller is a
 * CREATOR who may hold assignments from many agencies, so the ambient tenant
 * context must NOT narrow the set. Ownership is STRUCTURAL — the assignment is
 * resolved within `creator_id`, so a non-owned ULID is simply 404 (one creator
 * can never read or post to another's thread). Allowlisted in
 * docs/security/tenancy.md §4.
 */
final class CreatorMessageController
{
    use HandlesMessageAttachments;

    public function __construct(
        private readonly MessageThreadService $threads,
        private readonly MessageService $messages,
        private readonly MessageAttachmentUploadService $attachmentUploads,
    ) {}

    public function index(Request $request, string $assignment): JsonResponse
    {
        [$viewer, $thread] = $this->resolve($request, $assignment);

        $beforeId = $this->resolveBeforeCursor($request, $thread);
        $page = $this->messages->pageForThread($thread, $beforeId);

        return response()->json([
            'data' => MessageResource::collection($page['messages'])->resolve($request),
            'meta' => [
                'thread' => $this->messages->threadMeta($thread, $viewer),
                'has_more' => $page['has_more'],
            ],
        ]);
    }

    public function store(SendMessageRequest $request, string $assignment): JsonResponse
    {
        [$viewer, $thread] = $this->resolve($request, $assignment);

        try {
            [$kind, $body, $attachments] = $this->resolveSendPayload($request, $thread);

            $message = $this->messages->sendHumanMessage(
                $thread,
                $viewer,
                MessageSenderRole::Creator,
                $kind,
                $body,
                $attachments,
            );
        } catch (MessageThreadClosedException $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, $e->errorCode, $e->getMessage());
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'message.attachment_invalid', $e->getMessage());
        }

        return (new MessageResource($message->load('sender:id,name')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function attachmentInit(Request $request, string $assignment): JsonResponse
    {
        [, $thread] = $this->resolve($request, $assignment);

        return $this->attachmentInitResponse($request, $thread);
    }

    public function attachmentComplete(Request $request, string $assignment): JsonResponse
    {
        [, $thread] = $this->resolve($request, $assignment);

        return $this->attachmentCompleteResponse($request, $thread);
    }

    public function markRead(Request $request, string $assignment): JsonResponse
    {
        [$viewer, $thread] = $this->resolve($request, $assignment);

        $marked = $this->messages->markThreadReadForUser($thread, $viewer);

        return response()->json(['meta' => ['marked' => $marked, 'unread_count' => 0]]);
    }

    /**
     * Resolve the authenticated creator's OWN assignment (404 otherwise) and the
     * lazily-provisioned thread.
     *
     * @return array{0: User, 1: MessageThread}
     */
    private function resolve(Request $request, string $assignmentUlid): array
    {
        /** @var User $user */
        $user = $request->user();
        $creator = $this->requireCreator($user);

        $assignment = CampaignAssignment::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->where('ulid', $assignmentUlid)
            ->first();

        if ($assignment === null) {
            abort(ErrorResponse::single(request(), Response::HTTP_NOT_FOUND, 'assignment.not_found', 'No assignment found.'));
        }

        return [$user, $this->threads->forAssignment($assignment)];
    }

    private function requireCreator(User $user): Creator
    {
        $creator = $user->creator;

        if ($creator === null) {
            abort(ErrorResponse::single(request(), Response::HTTP_NOT_FOUND, 'creator.not_found', 'No creator profile is associated with this user.'));
        }

        return $creator;
    }

    private function resolveBeforeCursor(Request $request, MessageThread $thread): ?int
    {
        $beforeUlid = $request->query('before');
        if (! is_string($beforeUlid) || $beforeUlid === '') {
            return null;
        }

        return Message::query()
            ->where('thread_id', $thread->id)
            ->where('ulid', $beforeUlid)
            ->value('id');
    }
}
