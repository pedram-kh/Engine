<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a human message send (Sprint 11). S3 covers the text path; S4
 * widens it with the attachment payload + the "attachment-only" path (a message
 * with files and no body). The "at least one of body / attachments" rule is the
 * shared invariant — an empty send is never valid.
 *
 * Authorization is handled by the controller (agency Gate / creator structural
 * ownership), so this request authorizes broadly.
 */
final class SendMessageRequest extends FormRequest
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
            'body' => ['nullable', 'string', 'max:5000', 'required_without:attachments'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*.upload_id' => ['required_with:attachments', 'string'],
            'attachments.*.mime_type' => ['required_with:attachments', 'string', 'max:255'],
            'attachments.*.name' => ['required_with:attachments', 'string', 'max:255'],
            'attachments.*.size_bytes' => ['required_with:attachments', 'integer', 'min:1'],
        ];
    }
}
