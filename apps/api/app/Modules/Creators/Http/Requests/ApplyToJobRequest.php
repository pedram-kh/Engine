<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests;

use App\Modules\Campaigns\Models\CampaignApplication;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a creator's application to a listed job (Jobs Board chunk 3,
 * AH-056, D5) on `POST creators/me/jobs/{campaign}/apply`.
 *
 * Apply is ONE TAP. The note is genuinely optional — the whole point of D5's
 * shape is that a creator can express interest without composing anything — so
 * the request has no required field at all and an empty body is a valid apply.
 *
 * The length cap lives here rather than on the column (which is `text`), the
 * same split `cancelled_reason` uses. `nullable` before `string` so an
 * explicit `null` and an omitted key behave identically.
 *
 * Everything else — is this job visible, has this creator already applied — is
 * a controller concern, because those are 404/409 outcomes rather than
 * validation errors and both need the shared visibility predicate.
 */
final class ApplyToJobRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:'.CampaignApplication::NOTE_MAX_LENGTH],
        ];
    }

    /**
     * The apply note, normalised: a whitespace-only note is stored as null
     * rather than as blank text, so "applied with a note" stays a meaningful
     * distinction for chunk 4's agency-side review column.
     */
    public function note(): ?string
    {
        $note = $this->input('note');

        if (! is_string($note)) {
            return null;
        }

        $note = trim($note);

        return $note === '' ? null : $note;
    }
}
