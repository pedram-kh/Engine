<?php

declare(strict_types=1);

namespace App\Modules\TalentPools\Http\Resources;

use App\Modules\Creators\Models\Creator;
use App\Modules\TalentPools\Models\TalentPoolMembership;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * One member row on the pool DETAIL page (Sprint 6 Chunk 2b). A slim creator
 * shape — display name + locale/category facts + a signed avatar URL — plus
 * the `added_at` pivot timestamp.
 *
 * The signed-avatar minting is the D-2b-7 list/detail boundary: it is bounded
 * here because the members endpoint PAGINATES (per-page count, not the whole
 * pool), so we sign at most one page's worth of avatars per request — never
 * the unbounded N+1 the slim roster LIST deliberately avoided.
 *
 * @mixin Creator
 */
final class TalentPoolMemberResource extends JsonResource
{
    private const int SIGNED_URL_TTL_MINUTES = 60;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $creator = $this->resource;
        assert($creator instanceof Creator);

        // The custom-pivot accessor ('pivot') is loaded by TalentPool::creators()
        // ->using(TalentPoolMembership). getRelationValue keeps this stan-clean
        // (Creator has no static $pivot property) and the instanceof narrows it.
        $pivot = $creator->getRelationValue('pivot');
        $addedAt = $pivot instanceof TalentPoolMembership
            ? $pivot->created_at->toIso8601String()
            : null;

        return [
            'id' => $creator->ulid,
            'type' => 'talent_pool_members',
            'attributes' => [
                'display_name' => $creator->display_name,
                'country_code' => $creator->country_code,
                'primary_language' => $creator->primary_language,
                'categories' => $creator->categories,
                'avatar_url' => $this->signedViewUrl($creator->avatar_path),
                'application_status' => $creator->application_status->value,
                // The pivot timestamp — when this creator was added to the pool.
                'added_at' => $addedAt,
            ],
        ];
    }

    /**
     * Mint a presigned GET URL against the private `media` disk, or null when
     * the path is null OR the disk is non-S3 (test fakes use the local driver,
     * which throws on temporaryUrl). Mirrors AgencyCreatorDetailResource.
     */
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
