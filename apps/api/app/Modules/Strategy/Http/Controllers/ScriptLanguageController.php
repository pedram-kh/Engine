<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Services\ScriptLanguages;
use App\Modules\Strategy\Services\ScriptLanguageService;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * PL-3.2b: the local script languages of a market (rulings 4 and 10). English is always there and is never listed as a row. ALL
 * THREE flags first (404), then brand, wave, the market of this wave (404), the policy.
 */
final class ScriptLanguageController
{
    use ResolvesStrategyRoute;

    public function __construct(private readonly ScriptLanguageService $languages) {}

    /** The market's local languages in order, and for each how many scripts (and texts) hold text in it. */
    public function show(Request $request, Agency $agency, Brand $brand, string $wave, string $market): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WaveScript::class);

        $row = WaveMarket::query()->where('wave_id', $model->id)->where('ulid', $market)->first();
        if ($row === null) {
            return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
        }

        return response()->json(['data' => self::payload($model, $row)]);
    }

    public function update(Request $request, Agency $agency, Brand $brand, string $wave, string $market): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);

        /** @var array{languages: list<string>} $data */
        $data = Validator::make($request->all(), [
            'languages' => ['present', 'array', 'max:'.(StrategyLimits::LOCAL_LANGUAGES_PER_MARKET + 5)],
            'languages.*' => ['required', 'string', 'regex:/^[a-z]{2}$/', 'not_in:en', 'distinct'],
        ])->validate();
        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->languages->set($brand, $model, $actor, $market, array_values($data['languages']));
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        $row = WaveMarket::query()->where('wave_id', $model->id)->where('ulid', $market)->firstOrFail();

        return response()->json(['data' => self::payload($model, $row)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(Wave $wave, WaveMarket $market): array
    {
        $local = ScriptLanguages::localOf($market->id);
        $usage = ScriptLanguages::usage($wave->id, $market->id, $local);

        return [
            'market' => $market->ulid,
            'max_local' => StrategyLimits::LOCAL_LANGUAGES_PER_MARKET,
            'languages' => array_map(static fn (string $code, int $position): array => [
                'code' => $code, 'position' => $position, 'scripts' => $usage[$code]['scripts'], 'texts' => $usage[$code]['texts'],
            ], $local, array_keys($local)),
        ];
    }

    /** All three flags, read this flag FIRST (it short-circuits). */
    private function flagOff(Request $request): ?JsonResponse
    {
        if (CreatorsScriptsEnabled::active()) {
            return null;
        }

        return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
    }
}
