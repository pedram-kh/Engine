<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a relationship-message send (AH-010a, D4). Mirrors
 * {@see SendMessageRequest} for the text + file-attachment paths and ADDS the
 * net-new link-attachment payload.
 *
 * The "at least one of body / attachments / links" rule is the shared invariant
 * — an empty send is never valid. Links are http/https-ONLY (the `regex`
 * structurally rejects `javascript:` / `data:` and any other scheme, per
 * AH-004's link rule).
 *
 * Authorization is the controller's job (the status-aware messaging gate), so
 * this request authorizes broadly.
 */
final class SendRelationshipMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:5000', 'required_without_all:attachments,links'],

            // Files — same caps as campaign messaging (≤10, thread-keyed upload).
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*.upload_id' => ['required_with:attachments', 'string'],
            'attachments.*.mime_type' => ['required_with:attachments', 'string', 'max:255'],
            'attachments.*.name' => ['required_with:attachments', 'string', 'max:255'],
            'attachments.*.size_bytes' => ['required_with:attachments', 'integer', 'min:1'],

            // Links — net-new (D4). http/https only; reject javascript:/data:.
            'links' => ['nullable', 'array', 'max:10'],
            'links.*.url' => ['required_with:links', 'string', 'max:2048', 'regex:#^https?://#i'],
            'links.*.name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
