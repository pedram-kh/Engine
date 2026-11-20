<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
use App\Modules\Strategy\Http\Resources\StrategyPresenter;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\Services\MatrixService;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use App\Modules\Strategy\Services\StrategyText;
use App\Modules\Strategy\Services\UspService;
use App\Modules\Strategy\StrategyLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * The strategy summary (for the stepper and the market tabs) and the audience title. Order in every action: flags,
 * brand, wave (404), policy, validation, service.
 */
final class StrategyController
{
    use ResolvesStrategyRoute;

    public function __construct(private readonly UspService $usps, private readonly MatrixService $matrix) {}

    public function show(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WaveUsp::class);

        /** @var array<string, int> $counts */
        $counts = WavePersona::query()->where('wave_id', $model->id)
            ->selectRaw('market, count(*) as n')->groupBy('market')->pluck('n', 'market')->map(static fn (mixed $n): int => (int) $n)->all();

        return response()->json([
            'data' => StrategyPresenter::summary($model, $uspCount = WaveUsp::query()->where('wave_id', $model->id)->count(), $counts, $this->matrix->progress($model, $uspCount)),
        ]);
    }

    public function audienceTitle(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('update', WaveUsp::class);

        /** @var array<string, mixed> $data */
        $data = Validator::make(
            StrategyText::cleanInput($request->all(), ['usp_audience_title']),
            ['usp_audience_title' => ['present', 'nullable', 'string', 'max:'.StrategyLimits::AUDIENCE_TITLE]],
        )->validate();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->usps->setAudienceTitle($brand, $model, $actor, $data['usp_audience_title']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        $counts = WavePersona::query()->where('wave_id', $model->id)
            ->selectRaw('market, count(*) as n')->groupBy('market')->pluck('n', 'market')->map(static fn (mixed $n): int => (int) $n)->all();

        return response()->json([
            'data' => StrategyPresenter::summary($model->refresh(), $uspCount = WaveUsp::query()->where('wave_id', $model->id)->count(), $counts, $this->matrix->progress($model, $uspCount)),
        ]);
    }
}
