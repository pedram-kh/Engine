<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\AngleOrigin;
use App\Modules\Strategy\Enums\MatrixLevel;
use App\Modules\Strategy\Models\WaveMatrixCell;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Waves\Models\Wave;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The messaging matrix (PL-2.2, plan sections 1.5 to 1.8, 3.2).
 *
 *   - `load` returns one market's matrix in a CONSTANT number of queries (USPs, personas, cells, hand-written pairs).
 *   - `save` writes 1 to 48 cell changes in ONE transaction under the WAVE ROW lock (taken first, like every strategy
 *     write). Per-field last write wins: only the fields a change sends are written. A Priority or Support cell needs a
 *     message line (ruling Q1); a cell that does not exist needs a level.
 *   - The SYNC (X6 to X8): a Priority or Support cell with a message line owns ONE angle on its persona and USP,
 *     `origin = matrix`, explanation = message line. Low or cleared removes it. A hand-written angle is never touched
 *     and never duplicated. {@see reconcile} is a pure function of the final cell and the angle that exists, so the
 *     same request twice writes nothing the second time.
 *   - INVARIANT (plan 1.3): every Priority/Support cell with a message line has exactly one angle on its pair
 *     (`matrix` or `manual`); no `matrix` angle exists without such a cell.
 *
 * One audit row per request, ids and counts and levels and field names, never the text.
 */
final class MatrixService
{
    /**
     * @return array{usps: Collection<int, WaveUsp>, personas: Collection<int, WavePersona>, cells: Collection<int, WaveMatrixCell>, manual: array<string, array{ulid: string, text: string}>}
     */
    public function load(Wave $wave, string $marketUlid): array
    {
        $market = PersonaService::assertMarketInWave($wave, $marketUlid);

        $usps = WaveUsp::query()->where('wave_id', $wave->id)->orderBy('position')->orderBy('id')->get();
        $personas = WavePersona::query()->where('wave_id', $wave->id)->where('market_id', $market->id)->orderBy('slot')->orderBy('id')->get();
        $cells = WaveMatrixCell::query()->where('wave_id', $wave->id)->where('market_id', $market->id)->orderBy('id')->get();

        $manual = [];
        if ($personas->isNotEmpty()) {
            foreach (WavePersonaAngle::query()
                ->where('wave_id', $wave->id)->where('origin', AngleOrigin::Manual->value)
                ->whereIn('persona_id', $personas->modelKeys())
                ->get(['persona_id', 'usp_id', 'ulid', 'explanation']) as $a) {
                $manual[$a->persona_id.':'.$a->usp_id] = ['ulid' => $a->ulid, 'text' => $a->explanation];
            }
        }

        return ['usps' => $usps, 'personas' => $personas, 'cells' => $cells, 'manual' => $manual];
    }

    /**
     * The wave-level progress: set cells and the cells that can exist (USPs times the personas of every market).
     *
     * @return array{cells_set: int, cells_total: int, by_market: list<array{market: string, set: int, total: int}>}
     */
    public function progress(Wave $wave, int $uspCount): array
    {
        /** @var array<int, int> $personas */
        $personas = WavePersona::query()->where('wave_id', $wave->id)
            ->selectRaw('market_id, count(*) as n')->groupBy('market_id')->pluck('n', 'market_id')->map(static fn (mixed $n): int => (int) $n)->all();
        /** @var array<int, int> $set */
        $set = WaveMatrixCell::query()->where('wave_id', $wave->id)
            ->selectRaw('market_id, count(*) as n')->groupBy('market_id')->pluck('n', 'market_id')->map(static fn (mixed $n): int => (int) $n)->all();

        $by = [];
        $totalSet = 0;
        $total = 0;
        foreach ($wave->markets as $market) {
            $n = ($personas[$market->id] ?? 0) * $uspCount;
            $s = $set[$market->id] ?? 0;
            $by[] = ['market' => $market->ulid, 'set' => $s, 'total' => $n];
            $totalSet += $s;
            $total += $n;
        }

        return ['cells_set' => $totalSet, 'cells_total' => $total, 'by_market' => $by];
    }

    /**
     * @param  list<array{persona: string, usp: string, level?: string|null, rationale?: string|null, message_line?: string|null, clear?: bool}>  $changes
     * @return array{results: list<array{persona: string, usp: string, cell: WaveMatrixCell|null, manual: bool, manual_angle: array{ulid: string, text: string}|null}>, wave: Wave}
     */
    public function save(Brand $brand, Wave $wave, User $actor, string $marketUlid, array $changes): array
    {
        StrategyGuard::assertBrandWritable($brand);

        return StrategyGuard::retryOnClash(fn (): array => DB::transaction(function () use ($wave, $actor, $marketUlid, $changes): array {
            $locked = StrategyGuard::lockWave($wave);
            $market = PersonaService::assertMarketInWave($locked, $marketUlid);

            $personas = WavePersona::query()
                ->where('wave_id', $locked->id)->where('agency_id', $locked->agency_id)->where('market_id', $market->id)
                ->whereIn('ulid', array_values(array_unique(array_column($changes, 'persona'))))->get()->keyBy('ulid');
            $usps = WaveUsp::query()
                ->where('wave_id', $locked->id)->where('agency_id', $locked->agency_id)
                ->whereIn('ulid', array_values(array_unique(array_column($changes, 'usp'))))->get()->keyBy('ulid');

            foreach ($changes as $change) {
                if (! $personas->has($change['persona']) || ! $usps->has($change['usp'])) {
                    throw new ModelNotFoundException;
                }
            }

            $personaIds = $personas->pluck('id')->all();
            $uspIds = $usps->pluck('id')->all();
            /** @var \Illuminate\Support\Collection<string, WaveMatrixCell> $cells */
            $cells = WaveMatrixCell::query()->where('wave_id', $locked->id)->whereIn('persona_id', $personaIds)->whereIn('usp_id', $uspIds)->get()
                ->keyBy(static fn (WaveMatrixCell $c): string => $c->persona_id.':'.$c->usp_id);
            /** @var \Illuminate\Support\Collection<string, WavePersonaAngle> $angles */
            $angles = WavePersonaAngle::query()->where('wave_id', $locked->id)->whereIn('persona_id', $personaIds)->whereIn('usp_id', $uspIds)->get()
                ->keyBy(static fn (WavePersonaAngle $a): string => $a->persona_id.':'.$a->usp_id);

            $count = ['cells_created' => 0, 'cells_updated' => 0, 'cells_cleared' => 0, 'cells_unchanged' => 0,
                'angles_created' => 0, 'angles_updated' => 0, 'angles_removed' => 0, 'kept_manual' => 0];
            $levels = [];
            $fields = [];
            $results = [];

            foreach ($changes as $i => $change) {
                $persona = $personas->get($change['persona']) ?? throw new ModelNotFoundException;
                $usp = $usps->get($change['usp']) ?? throw new ModelNotFoundException;
                $key = $persona->id.':'.$usp->id;
                $cell = $cells->get($key);
                $changed = false;

                if (($change['clear'] ?? false) === true) {
                    if ($cell !== null) {
                        $cell->delete();
                        $cells->forget($key);
                        $cell = null;
                        $count['cells_cleared']++;
                        $changed = true;
                    }
                } else {
                    if ($cell === null) {
                        $level = $change['level'] ?? null;
                        if ($level === null) {
                            throw ValidationException::withMessages(["cells.{$i}.level" => ['A cell that is not set needs a level.']]);
                        }
                        $cell = new WaveMatrixCell([
                            'agency_id' => $locked->agency_id,
                            'wave_id' => $locked->id,
                            'market_id' => $market->id,
                            'persona_id' => $persona->id,
                            'usp_id' => $usp->id,
                            'level' => MatrixLevel::from($level),
                            'rationale' => $change['rationale'] ?? null,
                            'message_line' => $change['message_line'] ?? null,
                            'source' => 'manual',
                        ]);
                        self::assertMessageLine($cell, $i);
                        $cell->save();
                        $cells->put($key, $cell);
                        $count['cells_created']++;
                        $fields = array_merge($fields, ['level', 'rationale', 'message_line']);
                        $levels[$cell->level->value] = ($levels[$cell->level->value] ?? 0) + 1;
                        $changed = true;
                    } else {
                        foreach (['level', 'rationale', 'message_line'] as $field) {
                            if (! array_key_exists($field, $change)) {
                                continue;
                            }
                            if ($field === 'level') {
                                $newLevel = $change['level'] ?? null;
                                if ($newLevel !== null) {
                                    $cell->level = MatrixLevel::from($newLevel);
                                }
                            } else {
                                $cell->{$field} = $change[$field];
                            }
                        }
                        self::assertMessageLine($cell, $i);
                        $dirty = array_values(array_intersect(['level', 'rationale', 'message_line'], array_keys($cell->getDirty())));
                        if ($dirty !== []) {
                            $cell->save();
                            $count['cells_updated']++;
                            $fields = array_merge($fields, $dirty);
                            $levels[$cell->level->value] = ($levels[$cell->level->value] ?? 0) + 1;
                            $changed = true;
                        }
                    }
                }

                $angle = $angles->get($key);
                $outcome = self::reconcile($locked, $persona, $usp, $cell, $angle);
                if ($outcome['angle'] === null) {
                    $angles->forget($key);
                } else {
                    $angles->put($key, $outcome['angle']);
                }
                foreach (['angles_created', 'angles_updated', 'angles_removed', 'kept_manual'] as $k) {
                    $count[$k] += $outcome[$k];
                }
                if (! $changed) {
                    $count['cells_unchanged']++;
                }

                $final = $angles->get($key);
                $isManual = $final?->origin === AngleOrigin::Manual;
                $results[] = [
                    'persona' => $persona->ulid,
                    'usp' => $usp->ulid,
                    'cell' => $cell,
                    'manual' => $isManual,
                    'manual_angle' => $isManual ? ['ulid' => $final->ulid, 'text' => $final->explanation] : null,
                ];
            }

            $wrote = $count['cells_created'] + $count['cells_updated'] + $count['cells_cleared'] + $count['angles_created']
                + $count['angles_updated'] + $count['angles_removed'] > 0;
            if ($wrote) {
                Audit::log(
                    action: AuditAction::WaveMatrixCellSaved,
                    actor: $actor,
                    subject: $locked,
                    metadata: [
                        'wave' => $locked->ulid,
                        'market' => $market->ulid,
                        'cells_created' => $count['cells_created'],
                        'cells_updated' => $count['cells_updated'],
                        'cells_cleared' => $count['cells_cleared'],
                        'cells_unchanged' => $count['cells_unchanged'],
                        'angles_created' => $count['angles_created'],
                        'angles_updated' => $count['angles_updated'],
                        'angles_removed' => $count['angles_removed'],
                        'kept_manual' => $count['kept_manual'],
                        'levels' => $levels,
                        'changed' => array_values(array_unique($fields)),
                    ],
                    agencyId: $locked->agency_id,
                );
            }

            return ['results' => $results, 'wave' => $locked];
        }));
    }

    /**
     * The one place the sync rule lives (plan 1.7). Given the FINAL state of a cell (or none) and the angle that exists on
     * the pair, make the angle right: create, update or remove a `matrix` angle; never touch a `manual` one.
     *
     * @return array{angle: WavePersonaAngle|null, angles_created: int, angles_updated: int, angles_removed: int, kept_manual: int}
     */
    public static function reconcile(Wave $wave, WavePersona $persona, WaveUsp $usp, ?WaveMatrixCell $cell, ?WavePersonaAngle $angle): array
    {
        $out = ['angle' => $angle, 'angles_created' => 0, 'angles_updated' => 0, 'angles_removed' => 0, 'kept_manual' => 0];
        $wants = $cell !== null && $cell->level->feedsAngle() && $cell->message_line !== null;

        if ($wants) {
            if ($angle === null) {
                $out['angle'] = WavePersonaAngle::query()->create([
                    'agency_id' => $wave->agency_id,
                    'wave_id' => $wave->id,
                    'persona_id' => $persona->id,
                    'usp_id' => $usp->id,
                    'explanation' => (string) $cell->message_line,
                    'origin' => AngleOrigin::Matrix,
                ]);
                $out['angles_created'] = 1;
            } elseif ($angle->origin === AngleOrigin::Matrix) {
                if ($angle->explanation !== $cell->message_line) {
                    $angle->explanation = (string) $cell->message_line;
                    $angle->save();
                    $out['angles_updated'] = 1;
                }
            } else {
                $out['kept_manual'] = 1;
            }

            return $out;
        }

        if ($angle !== null && $angle->origin === AngleOrigin::Matrix) {
            $angle->delete();
            $out['angle'] = null;
            $out['angles_removed'] = 1;
        }

        return $out;
    }

    /**
     * After a hand-written angle was removed (plan 1.7 row 15): if the pair's cell is Priority or Support with a message
     * line, the synced angle takes its place in the SAME transaction. Returns true when it did.
     */
    public static function reconcileAfterManualRemoval(Wave $wave, WavePersona $persona, WaveUsp $usp): bool
    {
        $cell = WaveMatrixCell::query()->where('persona_id', $persona->id)->where('usp_id', $usp->id)->first();
        if ($cell === null) {
            return false;
        }

        return self::reconcile($wave, $persona, $usp, $cell, null)['angles_created'] === 1;
    }

    private static function assertMessageLine(WaveMatrixCell $cell, int $index): void
    {
        if ($cell->level->feedsAngle() && ($cell->message_line === null || $cell->message_line === '')) {
            throw ValidationException::withMessages(["cells.{$index}.message_line" => ['A Priority or Support cell needs a message line.']]);
        }
    }
}
