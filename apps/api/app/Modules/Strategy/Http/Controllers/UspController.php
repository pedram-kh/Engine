<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
use App\Modules\Strategy\Http\Resources\StrategyPresenter;
use App\Modules\Strategy\Models\WaveMatrixCell;
use App\Modules\Strategy\Models\WavePersonaAngle;
use App\Modules\Strategy\Models\WaveUsp;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use App\Modules\Strategy\Services\StrategyText;
use App\Modules\Strategy\Services\UspService;
use App\Modules\Strategy\Services\UspUsage;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * List, create, change, reorder and delete USPs. Order in every action: flags, brand, wave (404), policy, validation,
 * service. A PATCH carries only the fields that changed.
 */
final class UspController
{
    use ResolvesStrategyRoute;

    private const TEXT_KEYS = ['title', 'explainer', 'creative_angle', 'copy_seed'];

    public function __construct(private readonly UspService $usps) {}

    public function index(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WaveUsp::class);

        return response()->json(['data' => $this->present($this->usps->list($model))]);
    }

    public function store(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('create', WaveUsp::class);

        $data = $this->validated($request, creating: true);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $usp = $this->usps->create($brand, $model, $actor, $data);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => StrategyPresenter::usp($usp, 0)], Response::HTTP_CREATED);
    }

    public function update(Request $request, Agency $agency, Brand $brand, string $wave, string $usp): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('update', WaveUsp::class);

        $data = $this->validated($request, creating: false);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $updated = $this->usps->update($brand, $model, $usp, $actor, $data);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => StrategyPresenter::usp($updated, UspUsage::angleCount($updated), UspUsage::cellCount($updated))]);
    }

    public function reorder(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('update', WaveUsp::class);

        /** @var array{order: list<string>} $data */
        $data = Validator::make($request->all(), [
            'order' => ['required', 'array', 'max:'.StrategyLimits::USP_MAX],
            'order.*' => ['required', 'string', 'size:26'],
        ])->validate();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $list = $this->usps->reorder($brand, $model, $actor, $data['order']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => $this->present($list)]);
    }

    public function destroy(Request $request, Agency $agency, Brand $brand, string $wave, string $usp): JsonResponse|Response
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('delete', WaveUsp::class);

        /** @var array{confirm_count?: int|null} $data */
        $data = Validator::make($request->all(), [
            'confirm_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
        ])->validate();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->usps->delete(
                $brand,
                $model,
                $usp,
                $actor,
                Gate::allows('deleteInUse', WaveUsp::class),
                isset($data['confirm_count']) ? (int) $data['confirm_count'] : null,
            );
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';

        /** @var array<string, mixed> $data */
        $data = Validator::make(StrategyText::cleanInput($request->all(), self::TEXT_KEYS), [
            'title' => [$presence, 'string', 'max:'.StrategyLimits::USP_TITLE],
            'explainer' => ['sometimes', 'nullable', 'string', 'max:'.StrategyLimits::USP_EXPLAINER],
            'creative_angle' => ['sometimes', 'nullable', 'string', 'max:'.StrategyLimits::USP_CREATIVE_ANGLE],
            'copy_seed' => ['sometimes', 'nullable', 'string', 'max:'.StrategyLimits::USP_COPY_SEED],
        ])->validate();

        return $data;
    }

    /**
     * @param  Collection<int, WaveUsp>  $usps
     * @return list<array<string, mixed>>
     */
    private function present(Collection $usps): array
    {
        /** @var array<int, int> $counts */
        $counts = $usps->isEmpty() ? [] : WavePersonaAngle::query()
            ->whereIn('usp_id', $usps->pluck('id'))
            ->selectRaw('usp_id, count(*) as n')->groupBy('usp_id')->pluck('n', 'usp_id')->map(static fn (mixed $n): int => (int) $n)->all();

        /** @var array<int, int> $cellCounts */
        $cellCounts = $usps->isEmpty() ? [] : WaveMatrixCell::query()
            ->whereIn('usp_id', $usps->pluck('id'))
            ->selectRaw('usp_id, count(*) as n')->groupBy('usp_id')->pluck('n', 'usp_id')->map(static fn (mixed $n): int => (int) $n)->all();

        return array_values($usps->map(static fn (WaveUsp $u): array => StrategyPresenter::usp($u, $counts[$u->id] ?? 0, $cellCounts[$u->id] ?? 0))->all());
    }
}
