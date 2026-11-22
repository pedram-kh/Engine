<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
use App\Modules\Strategy\Http\Resources\StepsPresenter;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\Services\CompletionService;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * "Mark as complete" and "Reopen" of USPs, Personas and Matrix (PL-2.5, K1). Order in every action: flags, brand, wave (404),
 * policy (all three agency roles, Q2), validation, service. `{step}` is `usps`, `personas` or `matrix` (any other: 404).
 */
final class CompletionController
{
    use ResolvesStrategyRoute;

    public function __construct(private readonly CompletionService $completions) {}

    public function mark(Request $request, Agency $agency, Brand $brand, string $wave, string $step): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('update', WaveUsp::class);
        $market = $this->market($request);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $warnings = $this->completions->mark($brand, $model, $actor, $step, $market);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => StepsPresenter::build($model), 'meta' => ['warnings' => $warnings]]);
    }

    public function reopen(Request $request, Agency $agency, Brand $brand, string $wave, string $step): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('update', WaveUsp::class);
        $market = $this->market($request);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->completions->reopen($brand, $model, $actor, $step, $market);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => StepsPresenter::build($model)]);
    }

    private function market(Request $request): ?string
    {
        /** @var array<string, mixed> $data */
        $data = Validator::make($request->query(), ['market' => ['sometimes', 'nullable', 'string', 'size:26']])->validate();

        return isset($data['market']) ? (string) $data['market'] : null;
    }
}
