<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Exceptions\MessageThreadClosedException;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageReadReceipt;
use App\Modules\Messaging\Models\MessageThread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The message read/write domain (Sprint 11). Shared by the agency and creator
 * HTTP surfaces so both behave identically (only the tenancy/ownership resolve
 * differs, in the controllers).
 *
 *   - {@see self::sendHumanMessage()} — terminal-guarded human send (D-13 + Q2).
 *   - {@see self::pageForThread()}    — chronological feed with a `before` cursor.
 *   - {@see self::markThreadReadForUser()} / {@see self::unreadCountForUser()}
 *     — per-user read state via receipts (idempotent, §5.6).
 *
 * "Unread" for a viewer = thread messages NOT authored by them (system messages
 * — sender_user_id null — count) that carry no receipt for them. Soft-deleted
 * messages are excluded by the model's SoftDeletes scope.
 */
final class MessageService
{
    public const int DEFAULT_PAGE_SIZE = 50;

    public function __construct(private readonly SendMessageNotifications $messageNotifications) {}

    /**
     * Send a human message (text or attachment-only). Terminal-guarded: a send
     * on a declined/rejected/cancelled assignment throws
     * {@see MessageThreadClosedException} (D-13 + Q2). Stamps the thread's
     * `last_message_at`.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     */
    public function sendHumanMessage(
        MessageThread $thread,
        User $sender,
        MessageSenderRole $role,
        MessageKind $kind,
        ?string $body,
        array $attachments = [],
    ): Message {
        $thread->loadMissing('assignment');

        if ($thread->humanSendBlocked()) {
            throw new MessageThreadClosedException;
        }

        $message = new Message([
            'thread_id' => $thread->id,
            'sender_user_id' => $sender->id,
            'sender_role' => $role,
            'kind' => $kind,
            'body' => $body,
            'attachments' => $attachments === [] ? null : array_values($attachments),
            'system_event_key' => null,
        ]);
        $message->save();

        $thread->forceFill(['last_message_at' => $message->created_at])->save();

        // Emit the counterparty in-app notification THROUGH NotificationService
        // (D-7) — both surfaces notify identically because the emit lives here.
        $this->messageNotifications->dispatch($thread, $message, $sender);

        return $message;
    }

    /**
     * Write a system message (D-4). No human sender (sender_user_id null,
     * sender_role/kind = system); the `$eventKey` is the AuditAction verb string
     * (the FE + digest render the localized line from it — never stored text,
     * D-5). NOT terminal-guarded: system messages write on terminal events too
     * (the closing event IS a system message). Stamps `last_message_at`.
     */
    public function writeSystemMessage(MessageThread $thread, string $eventKey): Message
    {
        $message = new Message([
            'thread_id' => $thread->id,
            'sender_user_id' => null,
            'sender_role' => MessageSenderRole::System,
            'kind' => MessageKind::System,
            'body' => null,
            'attachments' => null,
            'system_event_key' => $eventKey,
        ]);
        $message->save();

        $thread->forceFill(['last_message_at' => $message->created_at])->save();

        return $message;
    }

    /**
     * A chronological page of a thread's messages (oldest → newest within the
     * page). Without a cursor, returns the most-recent page. With `$beforeId`
     * (an internal message id), returns the page immediately older than it — the
     * "load earlier" history cursor for the chat UI.
     *
     * @return array{messages: Collection<int, Message>, has_more: bool}
     */
    public function pageForThread(MessageThread $thread, ?int $beforeId = null, int $perPage = self::DEFAULT_PAGE_SIZE): array
    {
        $query = Message::query()
            ->where('thread_id', $thread->id)
            ->with('sender:id,name')
            ->orderByDesc('id');

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        // Fetch one extra to know whether older messages remain.
        $rows = $query->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;

        $page = $rows->take($perPage)->reverse()->values();

        return ['messages' => $page, 'has_more' => $hasMore];
    }

    /**
     * Mark every message a viewer can see as read (idempotent). Inserts receipts
     * for the viewer's unread messages; a re-read is a no-op via the
     * `(message_id, user_id)` UNIQUE (insertOrIgnore). Returns the count newly
     * marked.
     */
    public function markThreadReadForUser(MessageThread $thread, User $user): int
    {
        $unreadIds = $this->unreadQuery($thread, $user)->pluck('messages.id');

        if ($unreadIds->isEmpty()) {
            return 0;
        }

        $now = now();
        $rows = $unreadIds
            ->map(static fn (int $id): array => [
                'message_id' => $id,
                'user_id' => $user->id,
                'read_at' => $now,
            ])
            ->all();

        MessageReadReceipt::query()->insertOrIgnore($rows);

        return count($rows);
    }

    public function unreadCountForUser(MessageThread $thread, User $user): int
    {
        return $this->unreadQuery($thread, $user)->count();
    }

    /**
     * The shared thread-meta envelope returned beside the message page on BOTH
     * the agency and creator surfaces — so the two behave identically.
     *
     * @return array<string, mixed>
     */
    public function threadMeta(MessageThread $thread, User $viewer): array
    {
        $thread->loadMissing('assignment');

        return [
            'id' => $thread->ulid,
            'assignment_id' => $thread->assignment?->ulid,
            'last_message_at' => $thread->last_message_at?->toIso8601String(),
            'unread_count' => $this->unreadCountForUser($thread, $viewer),
            'human_send_blocked' => $thread->humanSendBlocked(),
        ];
    }

    /**
     * The unread-for-this-viewer query: thread messages not authored by the
     * viewer (system messages, sender_user_id null, count) with no receipt for
     * the viewer.
     *
     * @return Builder<Message>
     */
    private function unreadQuery(MessageThread $thread, User $user): Builder
    {
        return Message::query()
            ->where('messages.thread_id', $thread->id)
            ->where(function (Builder $q) use ($user): void {
                $q->whereNull('messages.sender_user_id')
                    ->orWhere('messages.sender_user_id', '!=', $user->id);
            })
            ->whereNotExists(function ($q) use ($user): void {
                $q->selectRaw('1')
                    ->from('message_read_receipts')
                    ->whereColumn('message_read_receipts.message_id', 'messages.id')
                    ->where('message_read_receipts.user_id', $user->id);
            });
    }
}
