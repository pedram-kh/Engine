<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Resources;

use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WaveConceptDecision;
use App\Modules\Strategy\Models\WaveConceptImage;
use App\Modules\Strategy\Models\WaveConceptLink;
use App\Modules\Strategy\Services\ConceptLinkRules;
use App\Modules\Strategy\Services\ConceptService;
use App\Modules\Waves\Models\Wave;

/**
 * The concept response shape (PL-2.3 plan section 3.2). One shape for every concept endpoint. Never a storage key, a
 * presigned URL or a numeric id: images are API paths the web fetches with its own authentication. Expects the
 * relations of {@see ConceptService::hydrate} to be loaded.
 */
final class ConceptPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function concept(WaveConcept $concept, Wave $wave, string $agencyUlid, string $brandUlid, bool $resetToAwaiting = false): array
    {
        $base = '/api/v1/agencies/'.$agencyUlid.'/brands/'.$brandUlid.'/waves/'.$wave->ulid.'/strategy/concepts/'.$concept->ulid;
        $persona = $concept->persona;

        $latest = $concept->relationLoaded('latestDecision') ? $concept->getRelation('latestDecision') : null;

        return [
            'id' => $concept->ulid,
            'type' => 'wave_concept',
            'attributes' => [
                'market' => $concept->market->ulid,
                'number' => $concept->position,
                'title' => $concept->title,
                'status' => $concept->status->value,
                'persona' => ['id' => $persona->ulid, 'code' => $persona->code, 'title' => $persona->title, 'name' => $persona->name, 'color_key' => $persona->colorKey()->value],
                'funnel_stage' => $concept->funnel_stage?->value,
                'funnel_note' => $concept->funnel_note,
                'product' => $concept->product,
                'angle' => $concept->angle,
                'primary_message' => $concept->primary_message,
                'hypothesis' => $concept->hypothesis,
                'hook' => $concept->hook,
                'format_visual' => $concept->format_visual,
                'key_points' => $concept->key_points ?? [],
                'payoff' => $concept->payoff,
                'cta' => $concept->cta,
                'usps' => $concept->chips->map(static fn ($chip): array => [
                    'id' => $chip->usp->ulid,
                    'title' => $chip->usp->title,
                    'color_key' => $chip->usp->color_key->value,
                ])->values()->all(),
                'images' => $concept->images->map(static fn (WaveConceptImage $i): array => self::image($i, $base))->values()->all(),
                'links' => $concept->links->map(static fn (WaveConceptLink $l): array => self::link($l))->values()->all(),
                'decision' => $latest instanceof WaveConceptDecision ? self::decision($latest) : null,
                'reset_to_awaiting' => $resetToAwaiting,
                'created_at' => $concept->created_at->toIso8601String(),
                'updated_at' => $concept->updated_at->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function image(WaveConceptImage $image, string $conceptBase): array
    {
        return [
            'id' => $image->ulid,
            'original_filename' => $image->original_filename,
            'mime_type' => $image->mime_type,
            'size_bytes' => $image->size_bytes,
            'has_thumbnail' => $image->thumb_key !== null,
            'file_url' => $conceptBase.'/images/'.$image->ulid.'/file',
            'thumbnail_url' => $image->thumb_key !== null ? $conceptBase.'/images/'.$image->ulid.'/thumbnail' : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function link(WaveConceptLink $link): array
    {
        return ['id' => $link->ulid, 'url' => $link->url, 'title' => $link->title, 'domain' => ConceptLinkRules::domain($link->url)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function decision(WaveConceptDecision $d): array
    {
        return [
            'id' => $d->ulid,
            'status' => $d->status->value,
            'client_name' => $d->client_name,
            'note' => $d->note,
            'comment' => $d->comment,
            'source' => $d->source,
            'recorded_at' => $d->recorded_at->toIso8601String(),
            'recorded_by' => $d->recordedBy === null ? null : ['id' => $d->recordedBy->ulid, 'name' => $d->recordedBy->name],
        ];
    }
}
