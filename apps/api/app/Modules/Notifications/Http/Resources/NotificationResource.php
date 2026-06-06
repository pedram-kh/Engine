<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a {@see Notification} for the `/me/notifications` feed.
 *
 * The body text is NOT rendered here — Ch3 renders it client-side from `type`
 * + `data`. This resource exposes the structured primitives: the type, the
 * raw `data` render params, read state, the actor (when present), and the
 * polymorphic subject's public ULID (never the internal bigint id).
 *
 * @mixin Notification
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $notification = $this->resource;
        assert($notification instanceof Notification);

        return [
            'id' => $notification->ulid,
            'type' => 'notifications',
            'attributes' => [
                'notification_type' => $notification->type->value,
                'data' => $notification->data ?? [],
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at->toIso8601String(),
                'actor' => $this->actorPayload($notification),
                'subject' => $this->subjectPayload($notification),
            ],
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function actorPayload(Notification $notification): ?array
    {
        $actor = $notification->actor;

        if (! $actor instanceof User) {
            return null;
        }

        return [
            'id' => $actor->ulid,
            'name' => $actor->name,
        ];
    }

    /**
     * The subject's public handle — class basename + ULID. Resolved from the
     * eager-loaded morphTo when present; the internal bigint subject_id is
     * never exposed.
     *
     * @return array<string, string|null>|null
     */
    private function subjectPayload(Notification $notification): ?array
    {
        if ($notification->subject_type === null || $notification->subject_id === null) {
            return null;
        }

        $subject = $notification->subject;
        $ulid = $subject instanceof Model ? $subject->getRouteKey() : null;

        return [
            'type' => class_basename($notification->subject_type),
            'ulid' => is_string($ulid) ? $ulid : null,
        ];
    }
}
