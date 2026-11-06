<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Http\Controllers\Concerns\HandlesMessageRevisions;
use App\Modules\Messaging\Http\Controllers\Concerns\HandlesRelationshipMessageAttachments;
use App\Modules\Messaging\Http\Requests\SendRelationshipMessageRequest;
use App\Modules\Messaging\Http\Resources\RelationshipMessageResource;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\Messaging\Services\RelationshipMessageAttachmentUploadService;
use App\Modules\Messaging\Services\RelationshipMessageService;
use App\Modules\Messaging\Support\ContactMediaUrl;
use App\Modules\Messaging\Support\RelationshipSendState;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * The AGENCY relationship-messaging surface (AH-010a). Tenant-scoped under the
 * standard `tenancy.agency` + `tenancy` stack — so every request here is already
 * an ACTIVE member of {agency} (the middleware 404s non-members), which is the
 * org-level participation rule (Q4: any active member sees/replies).
 *
 *   GET  …/relationship-threads                          inbox roll-up
 *   GET  …/creators/{creator}/relationship-messages      thread feed
 *   POST …/creators/{creator}/relationship-messages      send (agency_user)
 *   POST …/creators/{creator}/relationship-messages/read mark read
 *
 * The send gate is the load-bearing security decision (D2): `canMessageRelationship`
 * (approved creator + roster + non-blacklisted). READS honour D6 — an EXISTING
 * thread's history stays readable to any active member even if the relation is
 * now blacklisted/declined, but a NEW thread can only be opened by someone who
 * currently passes the send gate.
 */
final class AgencyRelationshipMessageController
{
    use HandlesMessageRevisions;
    use HandlesRelationshipMessageAttachments;

    public function __construct(
        private readonly RelationshipMessageService $messages,
        private readonly RelationshipMessageAttachmentUploadService $attachmentUploads,
    ) {}

    /**
     * The inbox roll-up: every relationship thread this agency holds, with the
     * viewer's unread count + a last-message preview. Tenant-scoped (BelongsToAgency).
     */
    public function inbox(Request $request, Agency $agency): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $threads = RelationshipThread::query()
            // Only conversations that actually have a message (AH-012 D2) — no
            // empty ghosts, even if a transient/attachment row ever slips in.
            ->has('messages', '>=', 1, 'and', static fn (EloquentBuilder $q) => $q->withoutGlobalScope(SoftDeletingScope::class))
            ->with(['creator:id,ulid,display_name,avatar_path,erased_at', 'latestMessageWithTrashed'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $threads->map(fn (RelationshipThread $thread): array => [
                'id' => $thread->ulid,
                'type' => 'relationship_thread',
                'attributes' => [
                    'creator' => [
                        'id' => $thread->creator?->ulid,
                        'display_name' => $thread->creator?->display_name,
                        'is_erased' => $thread->creator?->isErased() ?? false,
                        // AH-013 — signed avatar for the contact-row image
                        // (bounded list → per-row signing is fine; null when
                        // unset or on a non-S3 test disk).
                        'avatar_url' => ContactMediaUrl::resolve($thread->creator?->avatar_path),
                    ],
                    'last_message_at' => $thread->last_message_at?->toIso8601String(),
                    'last_message_preview' => $this->preview($thread->latestMessageWithTrashed),
                    'last_message_is_deleted' => $thread->latestMessageWithTrashed?->deleted_at !== null,
                    'unread_count' => $this->messages->unreadCountForUser($thread, $viewer),
                ],
            ])->all(),
        ]);
    }

    public function index(Request $request, Agency $agency, Creator $creator): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $thread = $this->resolveThreadForRead($agency, $creator);

        $beforeId = $this->resolveBeforeCursor($request, $thread);
        $page = $this->messages->pageForThread($thread, $beforeId);

        return response()->json([
            'data' => RelationshipMessageResource::collection($page['messages'])->resolve($request),
            'meta' => [
                // The send-state rides the READ payload so the client can render
                // a closed thread up front instead of discovering closure by
                // POSTing into a 403 (AH-051 follow-up).
                'thread' => [
                    ...$this->messages->threadMeta($thread, $viewer),
                    ...RelationshipSendState::for($viewer, $creator, $agency),
                ],
                'has_more' => $page['has_more'],
            ],
        ]);
    }

    public function store(SendRelationshipMessageRequest $request, Agency $agency, Creator $creator): JsonResponse
    {
        Gate::authorize('canMessageRelationship', [$creator, $agency]);

        /** @var User $sender */
        $sender = $request->user();
        $thread = $this->messages->provisionForPair($agency, $creator);

        try {
            [$kind, $body, $attachments] = $this->resolveSendPayload($request, $thread);

            $message = $this->messages->sendHumanMessage(
                $thread,
                $sender,
                MessageSenderRole::AgencyUser,
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

    public function markRead(Request $request, Agency $agency, Creator $creator): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $thread = $this->resolveThreadForRead($agency, $creator);

        $marked = $this->messages->markThreadReadForUser($thread, $viewer);

        return response()->json(['meta' => ['marked' => $marked, 'unread_count' => 0]]);
    }

    public function attachmentInit(Request $request, Agency $agency, Creator $creator): JsonResponse
    {
        Gate::authorize('canMessageRelationship', [$creator, $agency]);

        return $this->attachmentInitResponse($request, $this->messages->provisionForPair($agency, $creator));
    }

    public function attachmentComplete(Request $request, Agency $agency, Creator $creator): JsonResponse
    {
        Gate::authorize('canMessageRelationship', [$creator, $agency]);

        return $this->attachmentCompleteResponse($request, $this->messages->provisionForPair($agency, $creator));
    }

    /**
     * AH-119 B2 — edit own message (flag-gated by route middleware). The agency scope (tenancy stack) narrows
     * the thread lookup; `canMessageRelationship` is NOT required to delete, and edit checks it in the service.
     */
    public function update(Request $request, Agency $agency, Creator $creator, string $message): JsonResponse
    {
        return $this->editMessageResponse($request, RelationshipThread::query()->where('creator_id', $creator->id)->first(), $message);
    }

    /**
     * AH-119 B2 — delete own message (allowed when the relationship has since closed).
     */
    public function destroy(Request $request, Agency $agency, Creator $creator, string $message): JsonResponse
    {
        return $this->deleteMessageResponse($request, RelationshipThread::query()->where('creator_id', $creator->id)->first(), $message);
    }

    /**
     * Resolve the thread for a READ. An EXISTING thread is readable by any
     * active member (D6 — history stays readable). With no thread yet, opening
     * one requires the current send gate (you cannot start a conversation with
     * someone you may not message) — but opening alone does NOT persist a row
     * (AH-012 D1): we return a TRANSIENT thread, and the row materializes only
     * on the first send / attachment-upload. The service tolerates the
     * unsaved thread (empty feed, zero unread).
     */
    private function resolveThreadForRead(Agency $agency, Creator $creator): RelationshipThread
    {
        $thread = RelationshipThread::query()
            ->where('creator_id', $creator->id)
            ->first();

        if ($thread !== null) {
            return $thread;
        }

        Gate::authorize('canMessageRelationship', [$creator, $agency]);

        return $this->messages->transientThread($agency, $creator);
    }

    private function resolveBeforeCursor(Request $request, RelationshipThread $thread): ?int
    {
        $beforeUlid = $request->query('before');
        if (! is_string($beforeUlid) || $beforeUlid === '') {
            return null;
        }

        return RelationshipMessage::query()->withTrashed()
            ->where('thread_id', $thread->id)
            ->where('ulid', $beforeUlid)
            ->value('id');
    }

    private function preview(?RelationshipMessage $message): ?string
    {
        if ($message === null || $message->deleted_at !== null) {
            return null;
        }

        return $message->body ?? '';
    }
}
