<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers\Concerns;

use App\Core\Errors\ErrorResponse;
use App\Modules\Messaging\Enums\MessageKind;
use App\Modules\Messaging\Http\Requests\SendMessageRequest;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Services\MessageAttachmentUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Shared attachment glue for the agency + creator message controllers (Sprint
 * 11, D-6) — so both surfaces present an identical init / complete / send
 * payload contract. Each controller resolves its own thread (agency binding +
 * gate vs. creator structural ownership) and then delegates here.
 *
 * Requires the using controller to expose a {@see MessageAttachmentUploadService}
 * `$this->attachmentUploads`.
 */
trait HandlesMessageAttachments
{
    /**
     * Initiate a presigned upload scoped to the thread's prefix.
     */
    protected function attachmentInitResponse(Request $request, MessageThread $thread): JsonResponse
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

    /**
     * Verify a presigned upload landed under the thread's prefix.
     */
    protected function attachmentCompleteResponse(Request $request, MessageThread $thread): JsonResponse
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
     * Resolve the validated send into (kind, body, verified attachments). Each
     * attachment's `upload_id` is re-verified against the thread prefix (the
     * isolation backstop, even after a prior complete). An attachment-only send
     * (files, no body) is a first-class path (D-6).
     *
     * @return array{0: MessageKind, 1: ?string, 2: array<int, array<string, mixed>>}
     *
     * @throws RuntimeException when an attachment does not belong to the thread.
     */
    protected function resolveSendPayload(SendMessageRequest $request, MessageThread $thread): array
    {
        $rawBody = $request->validated('body');
        $body = is_string($rawBody) && $rawBody !== '' ? $rawBody : null;

        /** @var array<int, array<string, mixed>> $rawAttachments */
        $rawAttachments = $request->validated('attachments') ?? [];

        $verified = [];
        foreach ($rawAttachments as $attachment) {
            $path = $this->attachmentUploads->completePresignedUpload($thread, (string) $attachment['upload_id']);
            $verified[] = [
                's3_path' => $path,
                'mime_type' => (string) $attachment['mime_type'],
                'name' => (string) $attachment['name'],
                'size_bytes' => (int) $attachment['size_bytes'],
            ];
        }

        $kind = $verified === []
            ? MessageKind::Text
            : ($body === null ? MessageKind::AttachmentOnly : MessageKind::Text);

        return [$kind, $body, $verified];
    }
}
