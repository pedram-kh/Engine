<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Concerns;

use App\Core\Errors\ErrorResponse;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Http\Requests\SendRelationshipMessageRequest;
use App\Modules\Messaging\Models\RelationshipThread;
use App\Modules\Messaging\Services\RelationshipMessageAttachmentUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Shared attachment glue for the agency + creator relationship-message
 * controllers (AH-010a, D4) — so both surfaces present an identical
 * init / complete / send payload contract. Mirrors
 * {@see HandlesMessageAttachments}, plus links and the synchronous EXIF strip.
 *
 * Requires the using controller to expose a
 * {@see RelationshipMessageAttachmentUploadService} `$this->attachmentUploads`.
 */
trait HandlesRelationshipMessageAttachments
{
    protected function attachmentInitResponse(Request $request, RelationshipThread $thread): JsonResponse
    {
        $validated = $request->validate([
            'mime_type' => ['required', 'string'],
            'size_bytes' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $result = $this->attachmentUploads->initiatePresignedUpload(
                $thread,
                (string) $validated['mime_type'],
                (int) $validated['size_bytes'],
            );
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'message.attachment_invalid', $e->getMessage());
        }

        return response()->json(['data' => $result]);
    }

    protected function attachmentCompleteResponse(Request $request, RelationshipThread $thread): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'string'],
        ]);

        try {
            $path = $this->attachmentUploads->completePresignedUpload($thread, (string) $validated['upload_id']);
        } catch (RuntimeException $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'message.attachment_invalid', $e->getMessage());
        }

        return response()->json(['data' => ['storage_path' => $path]]);
    }

    /**
     * Resolve the validated send into (kind, body, unified attachments). For each
     * FILE: re-verify the upload_id against the thread prefix (the isolation
     * backstop), then EXIF-strip it in place if it is a supported image — the
     * synchronous sanitise runs here, BEFORE the message row or any signed URL
     * exists (race-free). LINKS are passed through as `kind=link` entries (the
     * http/https validation already happened in the FormRequest).
     *
     * @return array{0: MessageKind, 1: ?string, 2: array<int, array<string, mixed>>}
     *
     * @throws RuntimeException when a file does not belong to the thread, never
     *                          landed, or (for an image) cannot be decoded /
     *                          exceeds the 50 MP guard — surfaced as a clean 422.
     */
    protected function resolveSendPayload(SendRelationshipMessageRequest $request, RelationshipThread $thread): array
    {
        $rawBody = $request->validated('body');
        $body = is_string($rawBody) && $rawBody !== '' ? $rawBody : null;

        /** @var array<int, array<string, mixed>> $rawFiles */
        $rawFiles = $request->validated('attachments') ?? [];
        /** @var array<int, array<string, mixed>> $rawLinks */
        $rawLinks = $request->validated('links') ?? [];

        $attachments = [];

        foreach ($rawFiles as $file) {
            $mimeType = (string) $file['mime_type'];
            $path = $this->attachmentUploads->completePresignedUpload($thread, (string) $file['upload_id']);

            // Synchronous EXIF strip on supported images, in place, before any
            // message row / signed URL (Q3). A non-image is a no-op.
            $this->attachmentUploads->sanitizeImageInPlace($path, $mimeType);

            $attachments[] = [
                'kind' => 'file',
                's3_path' => $path,
                'mime_type' => $mimeType,
                'name' => (string) $file['name'],
                'size_bytes' => (int) $file['size_bytes'],
            ];
        }

        foreach ($rawLinks as $link) {
            $attachments[] = [
                'kind' => 'link',
                'url' => (string) $link['url'],
                'name' => isset($link['name']) && is_string($link['name']) && $link['name'] !== ''
                    ? $link['name']
                    : null,
            ];
        }

        $kind = $attachments === []
            ? MessageKind::Text
            : ($body === null ? MessageKind::AttachmentOnly : MessageKind::Text);

        return [$kind, $body, $attachments];
    }
}
