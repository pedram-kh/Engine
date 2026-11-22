<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Resources;

use App\Core\Tenancy\BelongsToAgencyScope;
use App\Modules\Boards\Models\BoardCard;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Strategy\Enums\CreatorDecisionStatus;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Models\WaveCreatorDecision;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Notes\NoteService;
use App\Modules\Strategy\Services\WaveCreatorSource;
use App\Modules\Strategy\Services\WaveCreatorStatus;
use App\Modules\Strategy\Services\WaveCreatorStepState;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * `GET creators` (PL-3.1 plan 3.1): every market's slots with the derived status, the current decision and the board column,
 * the per-market summary and persona warning, the step state, and the campaign link state. A CONSTANT number of queries
 * however many slots the wave has (gate B29): the slots, the personas, the campaign, its applications and assignments, the
 * cards, the decisions, and the creators' example links.
 */
final class WaveCreatorPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function show(Wave $wave, Request $request): array
    {
        $campaign = WaveCreatorSource::campaign($wave);
        $markets = WaveMarket::query()->where('wave_id', $wave->id)->orderBy('position')->orderBy('id')->get();
        $slots = WaveCreator::query()->where('wave_id', $wave->id)->with('creator')->orderBy('market_id')->orderBy('slot')->get();
        $personas = WavePersona::query()->where('wave_id', $wave->id)->orderBy('slot')->orderBy('id')->get(['id', 'ulid', 'market_id', 'slot', 'code', 'title'])->groupBy('market_id');

        ['applications' => $applications, 'assignments' => $assignments] = WaveCreatorSource::maps($wave, $campaign);
        $cards = WaveCreatorCard::many($slots->pluck('creator')->filter()->values(), $request);

        /** @var Collection<int, BoardCard> $boardCards */
        $boardCards = $assignments->isEmpty() ? new Collection : BoardCard::query()->withoutGlobalScope(BelongsToAgencyScope::class)
            ->with('column:id,name,stage_key')->whereIn('assignment_id', $assignments->pluck('id')->all())->get()->keyBy('assignment_id');

        /** @var Collection<int, Collection<int, WaveCreatorDecision>> $decisions */
        $decisions = WaveCreatorDecision::query()->where('wave_id', $wave->id)->with('recordedBy:id,name')->orderByDesc('id')->get()->groupBy('wave_creator_id');

        $noteCounts = NoteService::creatorCounts($wave);
        $marketRows = [];
        $marketStates = [];
        foreach ($markets as $market) {
            $rows = [];
            $forState = [];
            foreach ($slots->where('market_id', $market->id) as $slot) {
                $derived = WaveCreatorStatus::derive($applications->get($slot->creator_id), $assignments->get($slot->creator_id));
                $history = $decisions->get($slot->id, new Collection);
                $latest = $history->first();
                $assignment = $assignments->get($slot->creator_id);
                $card = $assignment === null ? null : $boardCards->get($assignment->id);
                $persona = $slot->persona_id === null ? null : $personas->get($market->id, new Collection)->firstWhere('id', $slot->persona_id);

                $rows[] = [
                    'id' => $slot->ulid,
                    'slot' => $slot->slot,
                    'slot_code' => $slot->slot_code,
                    'persona' => $persona === null ? null : ['id' => $persona->ulid, 'code' => $persona->code, 'title' => $persona->title],
                    'creator' => $cards[$slot->creator_id] ?? null,
                    'status' => $derived['status'],
                    'detail' => $derived['detail'],
                    'decision' => $latest === null ? null : self::decision($latest),
                    'decision_count' => $history->count(),
                    'board' => $card === null ? null : ['column' => $card->column?->name, 'stage' => $card->column?->stage_key],
                ];
                $forState[] = ['status' => $derived['status'], 'approved' => $latest?->status === CreatorDecisionStatus::Approved];
            }

            $state = WaveCreatorStepState::market($forState);
            $marketStates[] = $state;
            $marketRows[] = [
                'market' => $market->ulid,
                'code' => $market->code,
                'name' => $market->name,
                'state' => $state,
                'count' => count($rows),
                'notes_count' => $noteCounts[$market->ulid] ?? 0,
                'summary' => self::summary($rows, $personas->get($market->id, new Collection)),
                'slots' => $rows,
            ];
        }

        return [
            'campaign' => self::campaign($campaign),
            'limits' => ['per_market' => StrategyLimits::CREATORS_PER_MARKET, 'per_wave' => StrategyLimits::CREATORS_PER_WAVE],
            'state' => WaveCreatorStepState::wave($marketStates) + ['count' => $slots->count()],
            'markets' => $marketRows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function decision(WaveCreatorDecision $d): array
    {
        return [
            'id' => $d->ulid,
            'status' => $d->status->value,
            'client_name' => $d->client_name,
            'note' => $d->note,
            'comment' => $d->comment,
            'recorded_by' => $d->recordedBy?->name,
            'recorded_at' => $d->recorded_at->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, WavePersona>  $personas
     * @return array<string, mixed>
     */
    private static function summary(array $rows, Collection $personas): array
    {
        $by = [];
        foreach ($rows as $row) {
            $by[$row['status']] = ($by[$row['status']] ?? 0) + 1;
        }
        $ordered = [];
        foreach (WaveCreatorStatus::ALL as $status) {
            if (($by[$status] ?? 0) > 0) {
                $ordered[$status] = $by[$status];
            }
        }

        $perPersona = [];
        $warn = [];
        foreach ($personas as $persona) {
            $mine = array_filter($rows, static fn (array $r): bool => ($r['persona']['id'] ?? null) === $persona->ulid);
            $live = array_filter($mine, static fn (array $r): bool => $r['status'] !== WaveCreatorStatus::DECLINED);
            $perPersona[] = ['id' => $persona->ulid, 'code' => $persona->code, 'title' => $persona->title, 'count' => count($mine)];
            if ($live === []) {
                $warn[] = $persona->code;
            }
        }

        return [
            'total' => count($rows),
            'by_status' => $ordered,
            'personas' => $perPersona,
            'warnings' => ['personas_without_creator' => $warn],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function campaign(?Campaign $campaign): array
    {
        return $campaign === null
            ? ['linked' => false, 'id' => null, 'name' => null]
            : ['linked' => true, 'id' => $campaign->ulid, 'name' => $campaign->name];
    }
}
