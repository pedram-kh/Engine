<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Support\PendingMessageDigest;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Builds the per-user daily unread-messages digest (Sprint 11, D-9). The digest
 * is the messaging email channel (D-8 spec-divergence: there is NO immediate
 * per-message email; the digest IS the email path, opt-in / default OFF).
 *
 * It does NOT ride {@see NotificationService::notify()}
 * (in-app only by design) — it gates each candidate on
 * {@see NotificationService::isChannelEnabled()} for the `digest` channel itself.
 *
 * ⚠ Tenancy correctness (the digest's load-bearing surface). This runs in a
 * console with NO ambient agency context, so {@see BelongsToAgencyScope} is a
 * no-op — an unscoped MessageThread query would return EVERY agency's threads.
 * The aggregate is therefore scoped EXPLICITLY: agency recipients only ever see
 * threads of an agency they are a notifiable member of (`agency_id` filter);
 * creator recipients only their own assignments' threads (`creator_id` filter).
 * Agency A's digest can never reflect agency B's threads.
 *
 * Unread for the digest = thread messages with a HUMAN sender (system messages
 * excluded — they are not "a message from the counterparty") that are NOT the
 * recipient's own and carry no read receipt for them. Soft-deleted messages are
 * excluded by the model's SoftDeletes scope.
 */
final class MessageDigestService
{
    public function __construct(private readonly NotificationService $preferences) {}

    /**
     * Every recipient with at least one unread human message AND the `digest`
     * channel enabled for their messaging type. One entry per recipient.
     *
     * @return list<PendingMessageDigest>
     */
    public function pendingDigests(): array
    {
        $out = [];

        foreach ($this->agencyDigests() as $digest) {
            $out[] = $digest;
        }

        foreach ($this->creatorDigests() as $digest) {
            $out[] = $digest;
        }

        return $out;
    }

    /**
     * @return list<PendingMessageDigest>
     */
    private function agencyDigests(): array
    {
        $out = [];

        $agencies = Agency::query()->get();

        foreach ($agencies as $agency) {
            $threads = MessageThread::query()
                ->withoutGlobalScope(BelongsToAgencyScope::class)
                ->where('agency_id', $agency->getKey())
                ->with(['assignment.campaign', 'assignment.creator.user'])
                ->get();

            if ($threads->isEmpty()) {
                continue;
            }

            foreach ($agency->notifiableMembers() as $member) {
                $digest = $this->buildDigest(
                    $member,
                    $threads,
                    NotificationType::MessageReceivedByAgency,
                    counterparty: fn (MessageThread $thread): string => $this->creatorName($thread),
                );

                if ($digest !== null) {
                    $out[] = $digest;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<PendingMessageDigest>
     */
    private function creatorDigests(): array
    {
        $out = [];

        $creators = Creator::query()
            ->whereNotNull('user_id')
            ->with('user')
            ->get();

        foreach ($creators as $creator) {
            $user = $creator->user;
            if (! $user instanceof User) {
                continue;
            }

            $threads = MessageThread::query()
                ->withoutGlobalScope(BelongsToAgencyScope::class)
                ->whereHas(
                    'assignment',
                    static fn ($query) => $query
                        ->withoutGlobalScope(BelongsToAgencyScope::class)
                        ->where('creator_id', $creator->getKey()),
                )
                ->with(['assignment.campaign', 'assignment.agency'])
                ->get();

            if ($threads->isEmpty()) {
                continue;
            }

            $digest = $this->buildDigest(
                $user,
                $threads,
                NotificationType::MessageReceivedByCreator,
                counterparty: fn (MessageThread $thread): string => $this->agencyName($thread),
            );

            if ($digest !== null) {
                $out[] = $digest;
            }
        }

        return $out;
    }

    /**
     * Assemble one recipient's digest from their accessible threads, or null
     * when they have no unread or have opted OUT of the digest channel.
     *
     * @param  EloquentCollection<int, MessageThread>  $threads
     * @param  callable(MessageThread): string  $counterparty
     */
    private function buildDigest(
        User $recipient,
        EloquentCollection $threads,
        NotificationType $type,
        callable $counterparty,
    ): ?PendingMessageDigest {
        if (! $this->preferences->isChannelEnabled($recipient, $type, NotificationChannel::Digest)) {
            return null;
        }

        $unreadByThread = $this->unreadCountsByThread($threads->modelKeys(), $recipient);

        if ($unreadByThread->isEmpty()) {
            return null;
        }

        $lines = [];
        $total = 0;

        foreach ($threads as $thread) {
            $count = (int) ($unreadByThread[$thread->getKey()] ?? 0);
            if ($count === 0) {
                continue;
            }

            $total += $count;
            $lines[] = [
                'campaign' => $this->campaignName($thread),
                'counterparty' => $counterparty($thread),
                'unread' => $count,
            ];
        }

        if ($total === 0) {
            return null;
        }

        return new PendingMessageDigest($recipient, $total, $lines);
    }

    /** The campaign name for a thread's assignment, with a localized fallback. */
    private function campaignName(MessageThread $thread): string
    {
        $name = $thread->assignment?->campaign?->name;

        return is_string($name) ? $name : __('messages.digest.unknown_campaign');
    }

    /** The creator (counterparty for an agency recipient) display name. */
    private function creatorName(MessageThread $thread): string
    {
        $user = $thread->assignment?->creator?->user;

        return $user instanceof User ? $user->name : __('messages.digest.unknown_counterparty');
    }

    /** The agency (counterparty for a creator recipient) name. */
    private function agencyName(MessageThread $thread): string
    {
        $name = $thread->assignment?->agency?->name;

        return is_string($name) ? $name : __('messages.digest.unknown_counterparty');
    }

    /**
     * Per-thread unread human-message counts for a viewer: keyed thread_id →
     * count, only threads with ≥1 unread present.
     *
     * @param  array<int, int>  $threadIds
     * @return Collection<int, int>
     */
    private function unreadCountsByThread(array $threadIds, User $viewer): Collection
    {
        if ($threadIds === []) {
            return collect();
        }

        return Message::query()
            ->whereIn('thread_id', $threadIds)
            ->whereNotNull('sender_user_id')
            ->where('sender_user_id', '!=', $viewer->getKey())
            ->whereNotExists(function ($query) use ($viewer): void {
                $query->selectRaw('1')
                    ->from('message_read_receipts')
                    ->whereColumn('message_read_receipts.message_id', 'messages.id')
                    ->where('message_read_receipts.user_id', $viewer->getKey());
            })
            ->selectRaw('thread_id, count(*) as unread')
            ->groupBy('thread_id')
            ->pluck('unread', 'thread_id');
    }
}
