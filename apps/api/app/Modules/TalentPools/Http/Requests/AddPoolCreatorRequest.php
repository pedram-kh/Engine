<?php

declare(strict_types=1);

namespace App\Modules\TalentPools\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the add-creator-to-pool body (Sprint 6 Chunk 2b, D-2b-8). The
 * creator is identified by ULID; the controller resolves it and applies the
 * `requireRosterRelation` gate (the creator must have an AgencyCreatorRelation
 * with this agency, any status — D-2b-5). A non-existent ULID 404s at
 * resolution, not 422, to avoid fingerprinting which ULIDs are valid.
 */
final class AddPoolCreatorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'creator_id' => ['required', 'string'],
        ];
    }
}
