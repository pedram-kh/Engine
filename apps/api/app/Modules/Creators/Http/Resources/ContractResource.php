<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Resources;

use App\Modules\Creators\Models\Contract;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * JSON transformer for a `contracts` row (contract-bridge chunk, D-5).
 * Surfaces a presigned GET `view_url` for `body_pdf_path` — never the raw
 * S3 path. Inline terms come through as rendered `body_markdown`.
 *
 * @mixin Contract
 */
final class ContractResource extends JsonResource
{
    private const int SIGNED_URL_TTL_MINUTES = 60;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Contract $contract */
        $contract = $this->resource;

        return [
            'id' => $contract->ulid,
            'type' => 'contract',
            'attributes' => [
                'kind' => $contract->kind->value,
                'title' => $contract->title,
                'body_markdown' => $contract->body_markdown !== '' ? $contract->body_markdown : null,
                'status' => $contract->status->value,
                'sent_at' => $contract->sent_at?->toIso8601String(),
                'signed_at' => $contract->signed_at?->toIso8601String(),
                'view_url' => $this->signedViewUrl($contract->body_pdf_path),
            ],
        ];
    }

    private function signedViewUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('media');
        if (! $disk instanceof AwsS3V3Adapter) {
            return null;
        }

        return $disk->temporaryUrl($path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
    }
}
