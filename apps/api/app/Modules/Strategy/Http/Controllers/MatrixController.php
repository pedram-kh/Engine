<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
use App\Modules\Strategy\Http\Resources\StrategyPresenter;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\Services\MatrixService;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use App\Modules\Strategy\Services\StrategyText;
use App\Modules\Strategy\StrategyLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The messaging matrix of a wave (PL-2.2): one market's matrix in one request, and the cell write (one cell or up to
 * 48, one transaction). Order in every action: flags, brand, wave (404), policy, validation, service.
 */
final class MatrixController
{
    use ResolvesStrategyRoute;

    public function __construct(private readonly MatrixService $matrix) {}

    public function show(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WaveUsp::class);

        $market = $request->query('market');
        if (! is_string($market) || ! Str::isUlid($market)) {
            return $this->refuse($request, StrategyRuleViolation::personaMarketNotInWave(is_string($market) ? $market : '?'));
        }

        try {
            $m = $this->matrix->load($model, $market);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => StrategyPresenter::matrix(
            $model, $market, $m['usps'], $m['personas'], $m['cells'], $m['manual'], $this->matrix->progress($model, $m['usps']->count()),
        )]);
    }

    public function save(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('update', WaveUsp::class);

        $input = $request->all();
        if (isset($input['cells']) && is_array($input['cells'])) {
            foreach ($input['cells'] as $i => $cell) {
                if (is_array($cell)) {
                    $input['cells'][$i] = StrategyText::cleanInput($cell, ['rationale', 'message_line']);
                }
            }
        }

        $limit = StrategyLimits::MATRIX_LINE;
        /** @var array{market: string, cells: list<array{persona: string, usp: string, level?: string, rationale?: string|null, message_line?: string|null, clear?: bool}>} $data */
        $data = Validator::make($input, [
            'market' => ['required', 'string', 'size:26'],
            'cells' => ['required', 'array', 'min:1', 'max:'.StrategyLimits::MATRIX_BATCH_MAX],
            'cells.*' => ['required', 'array'],
            'cells.*.persona' => ['required', 'string', 'size:26'],
            'cells.*.usp' => ['required', 'string', 'size:26'],
            'cells.*.level' => ['sometimes', 'string', Rule::in(['priority', 'support', 'low'])],
            'cells.*.rationale' => ['sometimes', 'nullable', 'string', 'max:'.$limit],
            'cells.*.message_line' => ['sometimes', 'nullable', 'string', 'max:'.$limit],
            'cells.*.clear' => ['sometimes', 'boolean'],
        ])->after(function ($validator) use ($input): void {
            $seen = [];
            foreach (($input['cells'] ?? []) as $i => $cell) {
                if (! is_array($cell)) {
                    continue;
                }
                $key = ($cell['persona'] ?? '').':'.($cell['usp'] ?? '');
                if (isset($seen[$key])) {
                    $validator->errors()->add("cells.{$i}.usp", 'The same cell appears twice in one request.');
                }
                $seen[$key] = true;
                if (($cell['clear'] ?? false) && (array_key_exists('level', $cell) || array_key_exists('rationale', $cell) || array_key_exists('message_line', $cell))) {
                    $validator->errors()->add("cells.{$i}.clear", 'A cleared cell carries no other field.');
                }
            }
        })->validate();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $out = $this->matrix->save($brand, $model, $actor, $data['market'], $data['cells']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        $uspCount = WaveUsp::query()->where('wave_id', $model->id)->count();

        return response()->json(['data' => [
            'cells' => array_map(
                static fn (array $r): array => StrategyPresenter::cell($r['cell'], $r['persona'], $r['usp'], $r['manual_angle']),
                $out['results'],
            ),
            'progress' => $this->matrix->progress($out['wave'], $uspCount),
        ]]);
    }
}
