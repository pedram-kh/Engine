<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Http\Controllers\Concerns\HandlesRelationshipMessageAttachments;
use App\Modules\Messaging\Http\Requests\SendRelationshipMessageRequest;
use App\Modules\Messaging\Http\Resources\RelationshipMessageResource;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\Messaging\Services\RelationshipMessageAttachmentUploadService;
use App\Modules\Messaging\Services\RelationshipMessageService;
use App\Modules\Messaging\Support\ContactMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * The CREATOR relationship-messaging surface (AH-010a). Symmetric with the
 * agency surface (Q5) but keyed by the {agency} ULID — a creator holds at most
 * one thread per agency, so the agency uniquely identifies it.
 *
 *   GET  /creators/me/relationship-threads                          inbox roll-up
 *   GET  /creators/me/relationship-threads/{agency}/messages        thread feed
 *   POST /creators/me/relationship-threads/{agency}/messages        send (creator)
 *   POST /creators/me/relationship-threads/{agency}/messages/read   mark read
 *
 * ⚠ The BelongsToAgency global scope is bypassed deliberately (the
 * CreatorMessageController precedent): the caller is a CREATOR who may hold
 * relationships with many agencies, so the ambient tenant context must not
 * narrow the set. Ownership is STRUCTURAL — threads are resolved within
 * `creator_id`, so another creator's thread is simply invisible. Send re-checks
 * the status-aware gate (D2); an existing thread stays readable (D6).
 */
final class CreatorRelationshipMessageController
{
    use HandlesRelationshipMessageAttachments;

    public function __construct(
        private readonly RelationshipMessageService $messages,
        private readonly RelationshipMessageAttachmentUploadService $attachmentUploads,
    ) {}

    /**
     * The inbox roll-up: every relationship thread this creator holds, across
     * all agencies, with the viewer's unread count + a last-message preview.
     */
    public function inbox(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $creator = $this->requireCreator($viewer);

        $threads = RelationshipThread::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            // Only conversations that actually have a message (AH-012 D2) — no
            // empty ghosts, even if a transient/attachment row ever slips in.
            ->has('messages')
            ->with(['agency:id,ulid,name,logo_path', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $threads->map(fn (RelationshipThread $thread): array => [
                'id' => $thread->ulid,
                'type' => 'relationship_thread',
                'attributes' => [
                    'agency' => [
                        'id' => $thread->agency->ulid,
                        'name' => $thread->agency->name,
                        'logo_path' => $thread->agency->logo_path,
                        // AH-013 — resolved logo for the contact-row image: an
                        // absolute URL passes through; a bare S3 key is signed.
                        'logo_url' => ContactMediaUrl::resolve($thread->agency->logo_path),
                    ],
                    'last_message_at' => $thread->last_message_at?->toIso8601String(),
                    'last_message_preview' => $this->preview($thread->latestMessage),
                    'unread_count' => $this->messages->unreadCountForUser($thread, $viewer),
                ],
            ])->all(),
        ]);
    }

    public function index(Request $request, Agency $agency): JsonResponse
    {
        [$viewer, , $thread] = $this->resolveForRead($request, $agency);

        $beforeId = $this->resolveBeforeCursor($request, $thread);
        $page = $this->messages->pageForThread($thread, $beforeId);

        return response()->json([
            'data' => RelationshipMessageResource::collection($page['messages'])->resolve($request),
            'meta' => [
                'thread' => $this->messages->threadMeta($thread, $viewer),
                'has_more' => $page['has_more'],
            ],
        ]);
    }

    public function store(SendRelationshipMessageRequest $request, Agency $agency): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $creator = $this->requireCreator($viewer);

        Gate::authorize('canMessageRelationship', [$creator, $agency]);
        $thread = $this->messages->provisionForPair($agency, $creator);

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
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'message.attachment_invalid', $e->getMessage());
        }

        return (new RelationshipMessageResource($message->load('sender:id,name')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function markRead(Request $request, Agency $agency): JsonResponse
    {
        [$viewer, , $thread] = $this->resolveForRead($request, $agency);

        $marked = $this->messages->markThreadReadForUser($thread, $viewer);

        return response()->json(['meta' => ['marked' => $marked, 'unread_count' => 0]]);
    }

    public function attachmentInit(Request $request, Agency $agency): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $creator = $this->requireCreator($viewer);

        Gate::authorize('canMessageRelationship', [$creator, $agency]);

        return $this->attachmentInitResponse($request, $this->messages->provisionForPair($agency, $creator));
    }

    public function attachmentComplete(Request $request, Agency $agency): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $creator = $this->requireCreator($viewer);

        Gate::authorize('canMessageRelationship', [$creator, $agency]);

        return $this->attachmentCompleteResponse($request, $this->messages->provisionForPair($agency, $creator));
    }

    /**
     * Resolve (viewer, creator, thread) for a READ. An EXISTING owned thread is
     * always readable by its creator (D6). With no thread yet, opening one
     * requires the current send gate — but opening alone does NOT persist a row
     * (AH-012 D1): we return a TRANSIENT thread, and the row materializes only
     * on the first send / attachment-upload.
     *
     * @return array{0: User, 1: Creator, 2: RelationshipThread}
     */
    private function resolveForRead(Request $request, Agency $agency): array
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $creator = $this->requireCreator($viewer);

        $thread = RelationshipThread::query()
            ->withoutGlobalScope(BelongsToAgencyScope::class)
            ->where('creator_id', $creator->id)
            ->where('agency_id', $agency->id)
            ->first();

        if ($thread === null) {
            Gate::authorize('canMessageRelationship', [$creator, $agency]);
            $thread = $this->messages->transientThread($agency, $creator);
        }

        return [$viewer, $creator, $thread];
    }

    private function requireCreator(User $user): Creator
    {
        $creator = $user->creator;

        if ($creator === null) {
            abort(ErrorResponse::single(request(), Response::HTTP_NOT_FOUND, 'creator.not_found', 'No creator profile is associated with this user.'));
        }

        return $creator;
    }

    private function resolveBeforeCursor(Request $request, RelationshipThread $thread): ?int
    {
        $beforeUlid = $request->query('before');
        if (! is_string($beforeUlid) || $beforeUlid === '') {
            return null;
        }

        return RelationshipMessage::query()
            ->where('thread_id', $thread->id)
            ->where('ulid', $beforeUlid)
            ->value('id');
    }

    private function preview(?RelationshipMessage $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return $message->body ?? '';
    }
}
