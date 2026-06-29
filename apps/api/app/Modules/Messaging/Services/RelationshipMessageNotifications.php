<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\MessageSenderRole;
use App\Modules\Messaging\Models\RelationshipMessage;
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationService;

/**
 * Emits the new-relationship-message in-app notification to the COUNTERPARTY
 * (AH-010a, D5) — THROUGH {@see NotificationService::notify()}, never a bespoke
 * path. The relationship analogue of {@see SendMessageNotifications}, with
 * relationship-shaped recipient resolution: it resolves the agency + creator
 * directly from the {@see RelationshipThread} (there is NO assignment to deref —
 * the campaign emitter's assignment->campaign chain would break here, which is
 * exactly why this is a parallel emitter and a parallel pair of types).
 *
 * Dual-recipient, two types:
 *   - creator sends → fan out to the agency's admins+managers
 *     ({@see Agency::notifiableMembers()}) as
 *     `message.relationship_received_by_agency`.
 *   - agency sends  → notify the creator's user as
 *     `message.relationship_received_by_creator`.
 *
 * NotificationService honours each recipient's per-type `in_app` preference
 * (default ON). Digest is deferred (D5 — in-app unread covers it). The thread is
 * the notification subject.
 */
final class RelationshipMessageNotifications
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function dispatch(RelationshipThread $thread, RelationshipMessage $message, User $sender): void
    {
        $thread->loadMissing(['agency', 'creator.user']);

        $agency = $thread->agency;
        $creator = $thread->creator;
        if ($agency === null || $creator === null) {
            return;
        }

        $creatorUser = $creator->user;

        $data = [
            'agency_name' => $agency->name,
            'creator_name' => $creatorUser instanceof User ? $creatorUser->name : null,
            'sender_name' => $sender->name,
            'thread_ulid' => $thread->ulid,
        ];

        // Creator → agency org fan-out.
        if ($message->sender_role === MessageSenderRole::Creator) {
            foreach ($agency->notifiableMembers() as $member) {
                $this->notifications->notify(
                    recipient: $member,
                    type: NotificationType::MessageRelationshipReceivedByAgency,
                    subject: $thread,
                    actor: $sender,
                    data: $data,
                );
            }

            return;
        }

        // Agency → the creator's user.
        if (! $creatorUser instanceof User) {
            return;
        }

        $this->notifications->notify(
            recipient: $creatorUser,
            type: NotificationType::MessageRelationshipReceivedByCreator,
            subject: $thread,
            actor: $sender,
            data: $data,
        );
    }
}
