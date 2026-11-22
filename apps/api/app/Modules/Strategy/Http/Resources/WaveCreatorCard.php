<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Resources;

use App\Modules\Agencies\Http\Resources\CreatorDiscoveryResource;
use App\Modules\Creators\Enums\VerificationLevel;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorPortfolioItem;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The creator part of a slot card and of a picker row (PL-3.1 Q2, Q3): the discovery card's fields plus the three the frame
 * shows and the data may or may not have: verified (only for a KYC or tier verified creator), region (the creators table
 * has no city) and an example-content link (only when a portfolio item has one). Nothing is drawn that the data does not
 * hold. One extra query for the example links of ALL cards, never one per card.
 */
final class WaveCreatorCard
{
    /**
     * @param  Collection<int, Creator>  $creators
     * @return array<int, array<string, mixed>> creator id => card
     */
    public static function many(Collection $creators, Request $request): array
    {
        $links = $creators->isEmpty() ? [] : CreatorPortfolioItem::query()
            ->whereIn('creator_id', $creators->pluck('id')->all())
            ->whereNotNull('external_url')
            ->orderBy('position')->orderBy('id')
            ->get(['creator_id', 'external_url'])
            ->unique('creator_id')
            ->pluck('external_url', 'creator_id')
            ->all();

        $out = [];
        foreach ($creators as $creator) {
            $base = (new CreatorDiscoveryResource($creator))->resolve($request);
            /** @var array<string, mixed> $attrs */
            $attrs = $base['attributes'];
            $erased = $creator->isErased();
            $out[$creator->id] = [
                'id' => $creator->ulid,
                'is_erased' => $erased,
                'display_name' => $erased ? null : $creator->display_name,
                'avatar_url' => $erased ? null : ($attrs['avatar_url'] ?? null),
                'country_code' => $erased ? null : $creator->country_code,
                'region' => $erased ? null : $creator->region,
                'age' => $erased ? null : ($attrs['age'] ?? null),
                'primary_language' => $erased ? null : $creator->primary_language,
                'secondary_languages' => $erased ? [] : array_values($creator->secondary_languages ?? []),
                'categories' => $erased ? [] : array_values($creator->categories ?? []),
                'verified' => ! $erased && in_array($creator->verification_level, [VerificationLevel::KycVerified, VerificationLevel::TierVerified], true),
                'example_content_url' => $erased ? null : ($links[$creator->id] ?? null),
            ];
        }

        return $out;
    }
}
