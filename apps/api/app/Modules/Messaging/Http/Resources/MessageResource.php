<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Resources;

use App\Modules\Messaging\Models\Message;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
                // AH-119 B2: a deleted message is MASKED here, the one place that decides (no body, no
                // attachments, no signed URL minted); `original_body` and `deleted_body` never leave the model.
                'body' => $this->isDeleted() ? null : $this->body,
                'attachments' => $this->isDeleted() ? [] : $this->presentAttachments(),
                'system_event_key' => $this->system_event_key,
                // AH-118 — ALWAYS present, null for lines that carry no data.
                'system_event_data' => $this->system_event_data,
                'is_own' => $this->sender_user_id !== null && $this->sender_user_id === $viewerId,
                'sender' => $this->sender_user_id !== null && $this->relationLoaded('sender') && $this->sender !== null
                    ? ['name' => $this->sender->name]
                    : null,
                'created_at' => $this->created_at->toIso8601String(),
                'edited_at' => $this->isDeleted() ? null : $this->edited_at?->toIso8601String(),
                'is_deleted' => $this->isDeleted(),
            ],
        ];
    }

    private function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * Echo each stored attachment ({s3_path, mime_type, name, size_bytes}) with
     * a freshly-minted signed GET URL. Mirrors CampaignDraftResource: returns
     * null `view_url` when the disk is not S3 (e.g. Storage::fake in tests).
     *
     * @return array<int, array<string, mixed>>
     */
    private function presentAttachments(): array
    {
        $attachments = $this->attachments ?? [];

        return array_values(array_map(function (array $item): array {
            $path = is_string($item['s3_path'] ?? null) ? $item['s3_path'] : null;

            return [
                's3_path' => $path,
                'mime_type' => $item['mime_type'] ?? null,
                'name' => $item['name'] ?? null,
                'size_bytes' => $item['size_bytes'] ?? null,
                'view_url' => $this->signedViewUrl($path),
            ];
        }, $attachments));
    }

    private function signedViewUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('media');
        if (! $disk instanceof AwsS3V3Adapter) {
            return null;
        }

        return $disk->temporaryUrl($path, now()->addMinutes(15));
    }
}
