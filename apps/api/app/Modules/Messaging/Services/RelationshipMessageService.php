<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Models\RelationshipMessageReadReceipt;
use App\Modules\Messaging\Models\RelationshipThread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * The relationship-message read/write domain (AH-010a). The parallel analogue
 * of {@see MessageService}, mirroring its shape against the relationship tables.
 *
 * This is deliberate DUPLICATION, not reuse: the campaign `messages.thread_id`
 * FK + MessageService's `messages`-table-bound queries forbid sharing without a
 * campaign-path change (AH-010 Step-0). Logged as duplication-debt with a named
 * consolidation trigger (docs/tech-debt.md) — extract a shared message contract
 * once it is safe to touch both surfaces together.
 *
 * Differences from the campaign service:
 *   - provisioning keys on the (agency_id, creator_id) UNIQUE, race-safe (D3);
 *   - there is NO terminal/`humanSendBlocked` gate — closure is enforced at the
 *     CONTROLLER via the messaging policy at send time (D6: open while the
 *     relation is active; blocked on blacklist / non-roster), history stays
 *     readable;
 *   - there are NO system messages, so every message has a human sender.
 */
final class RelationshipMessageService
{
    public const int DEFAULT_PAGE_SIZE = 50;

    public function __construct(private readonly RelationshipMessageNotifications $notifications) {}

    /**
     * Provision the one-per-pair relationship thread (D3). Idempotent + race-safe
     * via the `(agency_id, creator_id)` UNIQUE — both sides may initiate and a
     * concurrent double-create collides on the unique rather than duplicating.
     *
     * The global BelongsToAgency scope is bypassed (the named, greppable
     * construct): `agency_id` is set explicitly from the already-resolved pair,
     * so there is no cross-tenant read — provisioning is idempotent
     * infrastructure keyed on the UNIQUE, and the creator surface carries no
     * ambient agency context.
     */
    public function provisionForPair(Agency $agency, Creator $creator): RelationshipThread
    {
        try {
            return RelationshipThread::query()
                ->withoutGlobalScope(BelongsToAgencyScope::class)
                ->firstOrCreate(
                    ['agency_id' => $agency->id, 'creator_id' => $creator->id],
                );
        } catch (UniqueConstraintViolationException) {
            return RelationshipThread::query()
                ->withoutGlobalScope(BelongsToAgencyScope::class)
                ->where('agency_id', $agency->id)
                ->where('creator_id', $creator->id)
                ->firstOrFail();
        }
    }

    /**
     * Send a human message (text or attachment-only). NOT terminal-guarded — the
     * send-time permission check lives in the controller (the messaging gate,
     * D6). Stamps the thread's `last_message_at` and emits the counterparty
     * notification.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     */
    public function sendHumanMessage(
        RelationshipThread $thread,
        User $sender,
        MessageSenderRole $role,
        MessageKind $kind,
        ?string $body,
        array $attachments = [],
    ): RelationshipMessage {
        $message = new RelationshipMessage([
            'thread_id' => $thread->id,
            'sender_user_id' => $sender->id,
            'sender_role' => $role,
            'kind' => $kind,
            'body' => $body,
            'attachments' => $attachments === [] ? null : array_values($attachments),
        ]);
        $message->save();

        $thread->forceFill(['last_message_at' => $message->created_at])->save();

        $this->notifications->dispatch($thread, $message, $sender);

        return $message;
    }

    /**
     * A chronological page of a thread's messages (oldest → newest within the
     * page). Without a cursor, the most-recent page; with `$beforeId`, the page
     * immediately older than it (the "load earlier" history cursor).
     *
     * Each message is decorated with a non-persisted `read_by_counterparty`
     * boolean (AH-010b, D10) — whether the OTHER side has read it. Direction-aware:
     * an agency message is "read" when the creator's user holds a receipt; a
     * creator message is "read" when ANY agency member does (org-level, Q4).
     * Receipts are only ever written for non-senders, so this is the read tick on
     * the viewer's own bubbles (the resource only surfaces it for own messages).
     *
     * @return array{messages: Collection<int, RelationshipMessage>, has_more: bool}
     */
    public function pageForThread(RelationshipThread $thread, ?int $beforeId = null, int $perPage = self::DEFAULT_PAGE_SIZE): array
    {
        $query = RelationshipMessage::query()
            ->where('thread_id', $thread->id)
            ->with(['sender:id,name', 'readReceipts:id,message_id,user_id'])
            ->orderByDesc('id');

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        $rows = $query->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;

        $page = $rows->take($perPage)->reverse()->values();

        $thread->loadMissing('creator:id,user_id');
        $creatorUserId = $thread->creator?->user_id;

        $page->each(function (RelationshipMessage $message) use ($creatorUserId): void {
            $message->setAttribute('read_by_counterparty', $this->readByCounterparty($message, $creatorUserId));
        });

        return ['messages' => $page, 'has_more' => $hasMore];
    }

    /**
     * Mark every message a viewer can see as read (idempotent). Inserts receipts
     * for the viewer's unread messages; a re-read is a no-op via the
     * `(message_id, user_id)` UNIQUE (insertOrIgnore). Returns the count newly
     * marked.
     */
    public function markThreadReadForUser(RelationshipThread $thread, User $user): int
    {
        $unreadIds = $this->unreadQuery($thread, $user)->pluck('relationship_messages.id');

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

        RelationshipMessageReadReceipt::query()->insertOrIgnore($rows);

        return count($rows);
    }

    public function unreadCountForUser(RelationshipThread $thread, User $user): int
    {
        return $this->unreadQuery($thread, $user)->count();
    }

    /**
     * The shared thread-meta envelope returned beside the message page on BOTH
     * the agency and creator surfaces. No `human_send_blocked` — send permission
     * is a policy decision the controller surfaces (D6).
     *
     * @return array<string, mixed>
     */
    public function threadMeta(RelationshipThread $thread, User $viewer): array
    {
        return [
            'id' => $thread->ulid,
            'last_message_at' => $thread->last_message_at?->toIso8601String(),
            'unread_count' => $this->unreadCountForUser($thread, $viewer),
        ];
    }

    /**
     * Whether the counterparty has read this message (AH-010b, D10). Direction-aware:
     *   - agency_user sender → the creator's user must hold a receipt;
     *   - creator sender     → ANY receipt (only agency members can hold one).
     */
    private function readByCounterparty(RelationshipMessage $message, ?int $creatorUserId): bool
    {
        if ($message->sender_role === MessageSenderRole::AgencyUser) {
            if ($creatorUserId === null) {
                return false;
            }

            return $message->readReceipts->contains(
                static fn (RelationshipMessageReadReceipt $receipt): bool => $receipt->user_id === $creatorUserId,
            );
        }

        return $message->readReceipts->isNotEmpty();
    }

    /**
     * The unread-for-this-viewer query: thread messages not authored by the
     * viewer with no receipt for the viewer. (No system messages on this
     * surface — every message carries a human sender.)
     *
     * @return Builder<RelationshipMessage>
     */
    private function unreadQuery(RelationshipThread $thread, User $user): Builder
    {
        return RelationshipMessage::query()
            ->where('relationship_messages.thread_id', $thread->id)
            ->where('relationship_messages.sender_user_id', '!=', $user->id)
            ->whereNotExists(function ($q) use ($user): void {
                $q->selectRaw('1')
                    ->from('relationship_message_read_receipts')
                    ->whereColumn('relationship_message_read_receipts.message_id', 'relationship_messages.id')
                    ->where('relationship_message_read_receipts.user_id', $user->id);
            });
    }
}
