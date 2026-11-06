<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Exceptions\MessageThreadClosedException;
use App\Modules\Messaging\Http\Controllers\Concerns\HandlesMessageAttachments;
use App\Modules\Messaging\Http\Controllers\Concerns\HandlesMessageRevisions;
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
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * The AGENCY messaging surface (Sprint 11, D-11/D-16). Tenant-scoped under the
 * standard `tenancy.agency` + `tenancy` stack; route-model binding resolves
 * {agency}/{campaign}/{assignment} within the agency scope, and the
 * assert-belongs checks defend the campaign↔agency / assignment↔campaign edges
 * (the CampaignAssignmentReviewController precedent).
 *
 *   GET  …/campaigns/{campaign}/message-threads                  rollup (Messages tab)
 *   GET  …/campaigns/{campaign}/assignments/{assignment}/messages  thread feed
 *   POST …/campaigns/{campaign}/assignments/{assignment}/messages  send (agency_user)
 *   POST …/campaigns/{campaign}/assignments/{assignment}/messages/read  mark read
 *
 * Reads gate on `view` (any member); sends gate on `message` (admin + manager +
 * staff). Threads are lazily provisioned on first read/send (D-3), which heals
 * any thread-less assignment without a backfill migration.
 */
final class AgencyMessageController
{
    use HandlesMessageAttachments;
    use HandlesMessageRevisions;

    public function __construct(
        private readonly MessageThreadService $threads,
        private readonly MessageService $messages,
        private readonly MessageAttachmentUploadService $attachmentUploads,
    ) {}

    /**
     * The Messages-tab roll-up: every thread on the campaign with the viewer's
     * unread count + a last-message preview. Read-only.
     */
    public function rollup(Request $request, Agency $agency, Campaign $campaign): JsonResponse
    {
        $this->assertCampaignBelongsToAgency($campaign, $agency);
        Gate::authorize('view', $campaign);

        /** @var User $viewer */
        $viewer = $request->user();

        $threads = MessageThread::query()
            ->whereHas('assignment', static fn ($q) => $q->where('campaign_id', $campaign->id))
            ->with(['assignment.creator:id,ulid,display_name,erased_at', 'latestMessageWithTrashed'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $threads->map(fn (MessageThread $thread): array => [
                'id' => $thread->ulid,
                'type' => 'message_thread',
                'attributes' => [
                    'assignment_id' => $thread->assignment?->ulid,
                    'status' => $thread->assignment?->status->value,
                    'creator' => [
                        'display_name' => $thread->assignment?->creator?->display_name,
                        'is_erased' => $thread->assignment?->creator?->isErased() ?? false,
                    ],
                    'last_message_at' => $thread->last_message_at?->toIso8601String(),
                    'last_message_preview' => $this->preview($thread->latestMessageWithTrashed),
                    'last_message_is_deleted' => $thread->latestMessageWithTrashed?->deleted_at !== null,
                    'unread_count' => $this->messages->unreadCountForUser($thread, $viewer),
                ],
            ])->all(),
        ]);
    }

    public function index(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment): JsonResponse
    {
        $this->assertCampaignBelongsToAgency($campaign, $agency);
        $this->assertAssignmentBelongsToCampaign($assignment, $campaign);
        Gate::authorize('view', $campaign);

        /** @var User $viewer */
        $viewer = $request->user();
        $thread = $this->threads->forAssignment($assignment);

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

    public function store(SendMessageRequest $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment): JsonResponse
    {
        $this->assertCampaignBelongsToAgency($campaign, $agency);
        $this->assertAssignmentBelongsToCampaign($assignment, $campaign);
        Gate::authorize('message', $campaign);

        /** @var User $sender */
        $sender = $request->user();
        $thread = $this->threads->forAssignment($assignment);

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
        } catch (MessageThreadClosedException $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, $e->errorCode, $e->getMessage());
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'message.attachment_invalid', $e->getMessage());
        }

        return (new MessageResource($message->load('sender:id,name')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function attachmentInit(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment): JsonResponse
    {
        $this->assertCampaignBelongsToAgency($campaign, $agency);
        $this->assertAssignmentBelongsToCampaign($assignment, $campaign);
        Gate::authorize('message', $campaign);

        return $this->attachmentInitResponse($request, $this->threads->forAssignment($assignment));
    }

    public function attachmentComplete(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment): JsonResponse
    {
        $this->assertCampaignBelongsToAgency($campaign, $agency);
        $this->assertAssignmentBelongsToCampaign($assignment, $campaign);
        Gate::authorize('message', $campaign);

        return $this->attachmentCompleteResponse($request, $this->threads->forAssignment($assignment));
    }

    public function markRead(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment): JsonResponse
    {
        $this->assertCampaignBelongsToAgency($campaign, $agency);
        $this->assertAssignmentBelongsToCampaign($assignment, $campaign);
        Gate::authorize('view', $campaign);

        /** @var User $viewer */
        $viewer = $request->user();
        $thread = $this->threads->forAssignment($assignment);

        $marked = $this->messages->markThreadReadForUser($thread, $viewer);

        return response()->json(['meta' => ['marked' => $marked, 'unread_count' => 0]]);
    }

    /**
     * AH-119 B2 — edit own message (flag-gated by route middleware). The thread is looked up, never provisioned.
     */
    public function update(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment, string $message): JsonResponse
    {
        return $this->editMessageResponse($request, $this->reviseThread($agency, $campaign, $assignment), $message);
    }

    /**
     * AH-119 B2 — delete own message (allowed in a closed thread).
     */
    public function destroy(Request $request, Agency $agency, Campaign $campaign, CampaignAssignment $assignment, string $message): JsonResponse
    {
        return $this->deleteMessageResponse($request, $this->reviseThread($agency, $campaign, $assignment), $message);
    }

    private function reviseThread(Agency $agency, Campaign $campaign, CampaignAssignment $assignment): ?MessageThread
    {
        $this->assertCampaignBelongsToAgency($campaign, $agency);
        $this->assertAssignmentBelongsToCampaign($assignment, $campaign);
        Gate::authorize('message', $campaign);

        return MessageThread::query()->where('assignment_id', $assignment->id)->first();
    }

    private function resolveBeforeCursor(Request $request, MessageThread $thread): ?int
    {
        $beforeUlid = $request->query('before');
        if (! is_string($beforeUlid) || $beforeUlid === '') {
            return null;
        }

        return Message::query()->withTrashed()
            ->where('thread_id', $thread->id)
            ->where('ulid', $beforeUlid)
            ->value('id');
    }

    private function preview(?Message $message): ?string
    {
        if ($message === null || $message->deleted_at !== null) {
            return null; // a deleted last message reads as `last_message_is_deleted`, never as text
        }

        if ($message->kind === MessageKind::System) {
            return null; // The FE renders system previews from system_event_key.
        }

        return $message->body ?? '';
    }

    private function assertCampaignBelongsToAgency(Campaign $campaign, Agency $agency): void
    {
        if ($campaign->agency_id !== $agency->id) {
            abort(404);
        }
    }

    private function assertAssignmentBelongsToCampaign(CampaignAssignment $assignment, Campaign $campaign): void
    {
        if ($assignment->campaign_id !== $campaign->id) {
            abort(404);
        }
    }
}
