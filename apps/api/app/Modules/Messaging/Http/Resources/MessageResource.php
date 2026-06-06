<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Resources;

use App\Modules\Messaging\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single message on the wire (Sprint 11). `is_own` is computed against the
 * authenticated viewer so the chat UI can right-align the caller's own
 * messages. System messages (`kind = system`) carry a null sender + a
 * `system_event_key`; the body renders client-side from that key + the thread's
 * assignment context (D-5) — never from stored text.
 *
 * @mixin Message
 */
final class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewerId = $request->user()?->getKey();

        return [
            'id' => $this->ulid,
            'type' => 'message',
            'attributes' => [
                'kind' => $this->kind->value,
                'sender_role' => $this->sender_role->value,
                'body' => $this->body,
                'attachments' => $this->attachments ?? [],
                'system_event_key' => $this->system_event_key,
                'is_own' => $this->sender_user_id !== null && $this->sender_user_id === $viewerId,
                'sender' => $this->sender_user_id !== null && $this->relationLoaded('sender') && $this->sender !== null
                    ? ['name' => $this->sender->name]
                    : null,
                'created_at' => $this->created_at->toIso8601String(),
            ],
        ];
    }
}
