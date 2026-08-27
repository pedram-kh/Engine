<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the visibility-toggle body (Campaign attachments chunk B,
 * AH-092). The only writable field on an existing attachment — attach and
 * toggle are different write intents (§2.3 of the plan), so this request
 * never touches `agency_file_id`.
 */
final class UpdateCampaignAttachmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'visible_to_creators' => ['required', 'boolean'],
        ];
    }
}
