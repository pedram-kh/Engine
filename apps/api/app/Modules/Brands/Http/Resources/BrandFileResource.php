<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Resources;

use App\Modules\Brands\Models\BrandFile;
use App\Modules\Brands\Services\BrandFileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a BrandFile (PL-1.1). `storage_key` is never exposed; `download_url` is a fresh,
 * short-lived signed GET forced to attachment. Ulids only.
 *
 * @mixin BrandFile
 */
final class BrandFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $file = $this->resource;
        assert($file instanceof BrandFile);

        return [
            'id' => $file->ulid,
            'type' => 'brand_files',
            'attributes' => [
                'original_filename' => $file->original_filename,
                'mime_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
                'file_type' => $file->file_type->value,
                'download_url' => app(BrandFileUploadService::class)->signedDownloadUrl($file),
                'created_at' => $file->created_at->toIso8601String(),
            ],
            'relationships' => [
                'uploaded_by' => $file->uploadedBy === null ? null : [
                    'data' => [
                        'id' => $file->uploadedBy->ulid,
                        'type' => 'users',
                        'attributes' => ['name' => $file->uploadedBy->name],
                    ],
                ],
            ],
        ];
    }
}
