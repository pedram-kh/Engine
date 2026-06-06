<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Campaigns\Models\CampaignAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationService;

/**
 * Emits the new-message in-app notification to the COUNTERPARTY (Sprint 11,
 * D-7) — THROUGH {@see NotificationService::notify()}, never a bespoke path.
 * Dual-recipient, two types:
 *
 *   - creator sends  → fan out to the agency's admins+managers
 *                      ({@see Agency::notifiableMembers()},
 *                      the Ch2 fan-out) as `message.received_by_agency`.
 *   - agency sends   → notify the creator's user as `message.received_by_creator`.
 *
 * NotificationService honours each recipient's per-type `in_app` preference
 * (default ON) and writes nothing for opted-out recipients. NO audit row is
 * written on send (D-17). System messages (no human sender) never notify.
 */
final class SendMessageNotifications
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function dispatch(MessageThread $thread, Message $message, User $sender): void
    {
        if ($message->sender_user_id === null) {
            return; // System message — no counterparty notification.
        }

        $assignment = $thread->assignment;
        if ($assignment === null) {
            return;
        }

        $campaign = $assignment->campaign;
        $creator = $assignment->creator;
        if ($campaign === null || $creator === null) {
            return;
        }

        $data = [
            'campaign_name' => $campaign->name,
            'sender_name' => $sender->name,
            'assignment_ulid' => $assignment->ulid,
        ];

        if ($message->sender_role === MessageSenderRole::Creator) {
            $this->fanOutToAgency($assignment, $sender, $data);

            return;
        }

        // Agency-side send → the creator receives it.
        $recipient = $creator->user;
        if (! $recipient instanceof User) {
            return;
        }

        $this->notifications->notify(
            recipient: $recipient,
            type: NotificationType::MessageReceivedByCreator,
            subject: $assignment,
            actor: $sender,
            data: $data,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fanOutToAgency(CampaignAssignment $assignment, User $sender, array $data): void
    {
        $agency = $assignment->agency;
        if ($agency === null) {
            return;
        }

        foreach ($agency->notifiableMembers() as $member) {
            $this->notifications->notify(
                recipient: $member,
                type: NotificationType::MessageReceivedByAgency,
                subject: $assignment,
                actor: $sender,
                data: $data,
            );
        }
    }
}
