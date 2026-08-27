<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Resources;

use App\Modules\Agencies\Models\AgencyFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The thin `{id, original_filename, mime_type, size_bytes}` projection for
 * the Attachments tab's attach picker (`?for=select`, Campaign attachments
 * chunk B, AH-092 — the BrandOptionResource shape). No `download_url` (the
 * BROWSE gesture, not a download one — minting a signed URL for every row in
 * a picker list is the exact per-row-mint cost the D4 jobs-board-card
 * decision already ruled out), no `category` (v1 has exactly one value), no
 * `uploaded_by` relationship. `mime_type` and `size_bytes` are kept — unlike
 * a brand's name-only picker, a FILE picker row needs enough to render an
 * icon and a size caption without a second round-trip.
 *
 * @mixin AgencyFile
 */
final class AgencyFileOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $file = $this->resource;
        assert($file instanceof AgencyFile);

        return [
            'id' => $file->ulid,
            'type' => 'agency_files',
            'attributes' => [
                'original_filename' => $file->original_filename,
                'mime_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
            ],
        ];
    }
}
