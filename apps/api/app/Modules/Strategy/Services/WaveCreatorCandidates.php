<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Agencies\Concerns\FiltersCreatorColumns;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Creators\Models\Creator;
use App\Modules\Strategy\Http\Resources\WaveCreatorCard;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The picker's rows (PL-3.1 plan 1.5): the SERVER-built eligible set ({@see WaveCreatorSource}), filtered by the shared creator
 * filters (`q`, `language`, `category`, age: the same trait Discover and the roster use, unchanged) plus a multi-country
 * `country[]`. It does NOT use the Discover base query: that one requires `is_discoverable`, and an applicant who hid
 * themselves is still the agency's applicant (Q4).
 */
final class WaveCreatorCandidates
{
    use FiltersCreatorColumns;

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function page(Wave $wave, Campaign $campaign, ?WaveMarket $market, Request $request): array
    {
        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $query = WaveCreatorSource::eligible($wave, $campaign)
            ->select([
                'creators.id', 'creators.ulid', 'creators.display_name', 'creators.country_code', 'creators.region',
                'creators.date_of_birth', 'creators.primary_language', 'creators.secondary_languages', 'creators.accent',
                'creators.content_companions', 'creators.categories', 'creators.avatar_path', 'creators.erased_at',
                'creators.verification_level',
            ])
            ->orderBy('creators.display_name')->orderBy('creators.id');

        $countries = $request->query('country');
        if (is_array($countries)) {
            $clean = array_values(array_filter($countries, static fn (mixed $c): bool => is_string($c) && $c !== ''));
            if ($clean !== []) {
                $query->whereIn('creators.country_code', $clean);
            }
        }
        $this->applyCreatorFilters($query, $request);

        $paginator = $query->paginate($perPage)->withQueryString();

        /** @var Collection<int, Creator> $creators */
        $creators = new Collection($paginator->items());
        $ids = $creators->pluck('id')->all();

        $cards = WaveCreatorCard::many($creators, $request);
        ['applications' => $applications, 'assignments' => $assignments] = WaveCreatorSource::maps($wave, $campaign);
        $inWave = $ids === [] ? new Collection : WaveCreator::query()->where('wave_id', $wave->id)->whereIn('creator_id', $ids)->get(['creator_id', 'market_id'])->keyBy('creator_id');
        $marketUlids = $inWave->isEmpty() ? [] : WaveMarket::query()->where('wave_id', $wave->id)->pluck('ulid', 'id')->all();

        // Walk ruling (PL-3.2b): how many archived scripts each creator has in THIS market of this wave. They come back when the creator
        // is added or replaces someone here, as far as the limit of three live scripts allows, so the dialog says so before the click.
        $archived = $market === null || $ids === [] ? [] : WaveScript::query()
            ->where('wave_id', $wave->id)->where('market_id', $market->id)->whereIn('creator_id', $ids)->whereNotNull('archived_at')
            ->selectRaw('creator_id, count(*) as total')->groupBy('creator_id')->pluck('total', 'creator_id')->all();

        $rows = [];
        foreach ($creators as $creator) {
            $derived = WaveCreatorStatus::derive($applications->get($creator->id), $assignments->get($creator->id));
            $slot = $inWave->get($creator->id);
            $rows[] = $cards[$creator->id] + [
                'status' => $derived['status'],
                'detail' => $derived['detail'],
                'applied' => $applications->has($creator->id),
                'invited' => $assignments->has($creator->id),
                'already_in_wave' => $slot !== null,
                'in_market' => $slot === null ? null : ($marketUlids[$slot->market_id] ?? null),
                'archived_scripts' => (int) ($archived[$creator->id] ?? 0),
            ];
        }

        return [
            'data' => $rows,
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'market' => $market?->ulid,
                'market_countries' => $market === null ? [] : $market->countries()->pluck('country')->all(),
            ],
        ];
    }
}
