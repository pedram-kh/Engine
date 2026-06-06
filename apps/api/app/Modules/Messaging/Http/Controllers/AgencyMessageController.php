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
use App\Modules\Messaging\Http\Requests\SendMessageRequest;
use App\Modules\Messaging\Http\Resources\MessageResource;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\MessageService;
use App\Modules\Messaging\Services\MessageThreadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

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
    public function __construct(
        private readonly MessageThreadService $threads,
        private readonly MessageService $messages,
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
            ->with(['assignment.creator:id,ulid,display_name', 'latestMessage'])
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
                    ],
                    'last_message_at' => $thread->last_message_at?->toIso8601String(),
                    'last_message_preview' => $this->preview($thread->latestMessage),
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
            $message = $this->messages->sendHumanMessage(
                $thread,
                $sender,
                MessageSenderRole::AgencyUser,
                MessageKind::Text,
                (string) $request->validated('body'),
            );
        } catch (MessageThreadClosedException $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, $e->errorCode, $e->getMessage());
        }

        return (new MessageResource($message->load('sender:id,name')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
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

    private function preview(?Message $message): ?string
    {
        if ($message === null) {
            return null;
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
