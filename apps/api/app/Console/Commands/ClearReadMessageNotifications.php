<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\Messaging\Services\MessageService;
use App\Modules\Messaging\Services\RelationshipMessageService;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Console\Command;

/**
 * One-shot backlog clean-up for AH-108 (Q1, option A).
 *
 * From AH-108 on, opening a thread marks that thread's message notifications
 * read. Every message notification created BEFORE the deploy is still unread
 * even when the person read the conversation long ago, so on day one the new
 * Messages-tab badge, the bell and the app-icon badge would all count messages
 * nobody has anything left to read. This marks those rows read.
 *
 * RULE: an unread message notification is marked read when its thread has NO
 * unread messages for its recipient. The "unread messages" test is the services'
 * own ({@see MessageService::unreadCountForUser()} /
 * {@see RelationshipMessageService::unreadCountForUser()}), so there is one
 * definition of "unread" and this command cannot drift from the thread view. A
 * notification whose thread still holds something unread is left alone: that one
 * is real.
 *
 * Rows it cannot place (not a message type, a subject that is not the expected
 * kind, a thread or recipient that no longer exists) are left unread and counted
 * separately, never guessed at.
 *
 * IDEMPOTENT: it only ever moves rows from unread to read, so a second run finds
 * nothing to mark. `--dry-run` reports the same counts without writing. Deploy
 * step, straight after `queue:restart`: dry-run first, report the count, then the
 * real run; both counts go in the deploy log.
 */
final class ClearReadMessageNotifications extends Command
{
    protected $signature = 'notifications:clear-read-message-notifications
        {--dry-run : Report how many notifications would be marked read without writing}';

    protected $description = 'Mark unread message notifications read when their thread has nothing unread for the recipient (AH-108 backlog; idempotent).';

    public function handle(MessageService $campaignChat, RelationshipMessageService $relationshipChat): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $checked = 0;
        $marked = 0;
        $stillUnread = 0;
        $unplaceable = 0;

        $assignmentType = (new CampaignAssignment)->getMorphClass();
        $relationshipThreadType = (new RelationshipThread)->getMorphClass();

        Notification::query()
            ->whereNull('read_at')
            ->whereIn('type', array_map(
                static fn (NotificationType $type): string => $type->value,
                NotificationType::messageTypes(),
            ))
            ->with('recipient')
            ->orderBy('id')
            ->chunkById(200, function ($notifications) use (
                $campaignChat,
                $relationshipChat,
                $assignmentType,
                $relationshipThreadType,
                $dryRun,
                &$checked,
                &$marked,
                &$stillUnread,
                &$unplaceable,
            ): void {
                foreach ($notifications as $notification) {
                    $checked++;

                    $recipient = $notification->recipient;
                    $unread = $recipient instanceof User
                        ? $this->unreadMessagesInThread($notification, $recipient, $campaignChat, $relationshipChat, $assignmentType, $relationshipThreadType)
                        : null;

                    if ($unread === null) {
                        $unplaceable++;

                        continue;
                    }

                    if ($unread > 0) {
                        $stillUnread++;

                        continue;
                    }

                    if (! $dryRun) {
                        // Guarded on read_at IS NULL so a concurrent open of the
                        // thread (which marks it read itself) is never overwritten.
                        Notification::query()
                            ->whereKey($notification->getKey())
                            ->whereNull('read_at')
                            ->update(['read_at' => now()]);
                    }

                    $marked++;
                }
            });

        $this->info(sprintf(
            '%s %d unread message notification(s); %d %s, %d left unread (thread still has unread messages), %d could not be placed (left unread).',
            $dryRun ? 'Checked' : 'Processed',
            $checked,
            $marked,
            $dryRun ? 'would be marked read' : 'marked read',
            $stillUnread,
            $unplaceable,
        ));

        return self::SUCCESS;
    }

    /**
     * The recipient's unread-message count in the notification's thread, or null
     * when the notification cannot be placed in a thread.
     */
    private function unreadMessagesInThread(
        Notification $notification,
        User $recipient,
        MessageService $campaignChat,
        RelationshipMessageService $relationshipChat,
        string $assignmentType,
        string $relationshipThreadType,
    ): ?int {
        if ($notification->subject_id === null) {
            return null;
        }

        $isCampaignChat = in_array($notification->type, [
            NotificationType::MessageReceivedByCreator,
            NotificationType::MessageReceivedByAgency,
        ], true);

        if ($isCampaignChat) {
            if ($notification->subject_type !== $assignmentType) {
                return null;
            }

            $thread = MessageThread::withoutGlobalScopes()->where('assignment_id', $notification->subject_id)->first();

            return $thread === null ? null : $campaignChat->unreadCountForUser($thread, $recipient);
        }

        if ($notification->subject_type !== $relationshipThreadType) {
            return null;
        }

        $thread = RelationshipThread::withoutGlobalScopes()->find($notification->subject_id);

        return $thread === null ? null : $relationshipChat->unreadCountForUser($thread, $recipient);
    }
}
