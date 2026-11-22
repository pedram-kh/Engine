<?php

declare(strict_types=1);

namespace App\Modules\Waves\Steps;

use App\Modules\Agencies\Models\AgencyMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Contracts\WaveStrategyState;
use App\Modules\Waves\Enums\WaveStepSource;
use App\Modules\Waves\Enums\WaveStream;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveStep;
use Illuminate\Support\Collection;

/**
 * The wire shape of a step and of the whole tracker (PL-1.3 plan sections 3.2 and 1.7). Ulids only on the wire; no
 * internal id and no `agency_id`. "Today" is the requesting user's day (ruling Q12).
 *
 * An owner who is no longer an active member of the agency is sent as `owner: null` with `owner_is_former: true`.
 */
final class WaveTrackerPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function tracker(Wave $wave, User $viewer): array
    {
        $today = WaveTrackerCalculator::todayFor($viewer->timezone);
        /** @var Collection<int, WaveStep> $steps */
        $steps = WaveStep::query()->where('wave_id', $wave->id)->get()
            ->sortBy([['position', 'asc'], ['id', 'asc']])->values();

        $owners = self::owners($steps, $wave->agency_id);
        // PL-2.5: empty while strategy is OFF, so the payload is exactly PL-1.3's.
        $real = app(WaveStrategyState::class)->real($wave);
        $streams = WaveTrackerCalculator::streams($steps, $today);

        $present = array_column($streams, 'stream');
        $streamsOut = array_map(static function (array $s) use ($owners): array {
            /** @var list<int> $ids */
            $ids = $s['owner_ids'];
            unset($s['owner_ids']);
            $s['owners'] = array_values(array_filter(array_map(
                static fn (int $id): ?array => ($owners[$id]['active'] ?? false) ? ['id' => $owners[$id]['id'], 'name' => $owners[$id]['name']] : null,
                $ids,
            )));

            return $s;
        }, $streams);

        $order = array_map(static fn (WaveStream $s): string => $s->value, WaveStream::cases());
        $sorted = $steps->sortBy(static fn (WaveStep $s): array => [array_search($s->stream->value, $order, true), $s->position, $s->id])->values();

        return [
            'id' => $wave->ulid,
            'type' => 'wave_trackers',
            'attributes' => [
                'today' => $today,
                'streams' => $streamsOut,
                'absent_streams' => array_values(array_filter($order, static fn (string $s): bool => ! in_array($s, $present, true))),
                'steps' => $sorted->map(static fn (WaveStep $s): array => self::step($s, $today, $owners, $real))->all(),
                'health' => WaveTrackerCalculator::health($streams),
                'progress' => WaveTrackerCalculator::progress($steps),
            ],
        ];
    }

    /**
     * One step as the API returns it after an add or an update.
     *
     * @return array<string, mixed>
     */
    public static function stepResponse(WaveStep $step, User $viewer): array
    {
        $today = WaveTrackerCalculator::todayFor($viewer->timezone);

        $real = $step->isStrategy() ? app(WaveStrategyState::class)->real($step->wave) : [];

        return self::step($step, $today, self::owners(collect([$step]), $step->agency_id), $real);
    }

    /**
     * @param  array<int, array{id: string, name: string, active: bool}>  $owners
     * @param  array<string, array{state: string, markets_done: int, markets_total: int}>  $real  the real strategy states by step key (empty while strategy is OFF)
     * @return array<string, mixed>
     */
    private static function step(WaveStep $step, string $today, array $owners, array $real = []): array
    {
        $owner = $step->owner_user_id === null ? null : ($owners[$step->owner_user_id] ?? null);
        $active = $owner !== null && $owner['active'];

        $strategy = [];
        if ($step->step_key !== null && isset($real[$step->step_key])) {
            $r = $real[$step->step_key];
            $strategy = ['strategy' => [
                'state' => $r['state'],
                'markets_done' => $r['markets_done'],
                'markets_total' => $r['markets_total'],
                // F1 rows 2, 5 and 7: set by hand beyond the real step; the warning line is live.
                'hand_set_beyond' => $step->status_source === WaveStepSource::Manual && WaveStrategyRowRules::beyond($step->status->value, $r['state']),
            ]];
        }

        return [
            'id' => $step->ulid,
            'type' => 'wave_steps',
            'attributes' => $strategy + [
                'stream' => $step->stream->value,
                'key' => $step->step_key,
                'is_strategy' => $step->isStrategy(),
                'name' => $step->name,
                'position' => $step->position,
                'status' => $step->status->value,
                'status_source' => $step->status_source->value,
                'display_status' => WaveTrackerCalculator::displayStatus($step, $today),
                'days_overdue' => WaveTrackerCalculator::daysOverdue($step, $today),
                'starts_on' => $step->starts_on?->toDateString(),
                'due_on' => $step->due_on?->toDateString(),
                'completed_on' => $step->completed_on?->toDateString(),
                'owner' => $active ? ['id' => $owner['id'], 'name' => $owner['name']] : null,
                'owner_client' => $step->owner_client,
                'owner_is_former' => $step->owner_user_id !== null && ! $active,
            ],
        ];
    }

    /**
     * Every owner of the given steps with ONE users query and ONE membership query.
     *
     * @param  Collection<int, WaveStep>  $steps
     * @return array<int, array{id: string, name: string, active: bool}>
     */
    private static function owners(Collection $steps, int $agencyId): array
    {
        $ids = $steps->pluck('owner_user_id')->filter()->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        $users = User::query()->whereIn('id', $ids)->get(['id', 'ulid', 'name'])->keyBy('id');
        $active = AgencyMembership::query()
            ->where('agency_id', $agencyId)
            ->whereIn('user_id', $ids)
            ->whereNotNull('accepted_at')
            ->whereNull('deleted_at')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $out = [];
        foreach ($users as $id => $user) {
            $out[(int) $id] = ['id' => $user->ulid, 'name' => (string) $user->name, 'active' => in_array((int) $id, $active, true)];
        }

        return $out;
    }
}
