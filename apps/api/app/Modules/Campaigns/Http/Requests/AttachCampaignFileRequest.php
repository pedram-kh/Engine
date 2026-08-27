<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the attach body (Campaign attachments chunk B, AH-092). The file
 * is identified by ULID; the controller resolves it under the path agency and
 * 404s a cross-agency or unknown ulid, never a 422 (non-fingerprinting).
 * `visible_to_creators` is optional — an attach-and-make-visible in one call
 * is a convenience, not a requirement; omitted defaults to the column's own
 * `false` default (§2.3 of the plan).
 */
final class AttachCampaignFileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agency_file_id' => ['required', 'string'],
            'visible_to_creators' => ['sometimes', 'boolean'],
        ];
    }
}
