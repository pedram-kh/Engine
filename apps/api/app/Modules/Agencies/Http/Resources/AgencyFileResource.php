<?php

declare(strict_types=1);

namespace App\Modules\Agencies\Http\Resources;

use App\Modules\Agencies\Models\AgencyFile;
use App\Modules\Agencies\Services\AgencyFileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of an AgencyFile (AH-091, chunk A).
 *
 * Follows the JSON:API-inspired envelope from docs/04-API-DESIGN.md §4.
 * `storage_key` is NEVER exposed — the raw S3 key is an internal detail;
 * `download_url` (a fresh, short-lived signed GET, D4) is what every
 * consumer renders/uses instead.
 *
 * @mixin AgencyFile
 */
final class AgencyFileResource extends JsonResource
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
                'category' => $file->category->value,
                'download_url' => app(AgencyFileUploadService::class)->signedDownloadUrl($file),
                'created_at' => $file->created_at->toIso8601String(),
            ],
            'relationships' => [
                'uploaded_by' => $file->uploadedBy === null ? null : [
                    'data' => [
                        'id' => $file->uploadedBy->ulid,
                        'type' => 'users',
                        'attributes' => [
                            'name' => $file->uploadedBy->name,
                        ],
                    ],
                ],
            ],
        ];
    }
}
