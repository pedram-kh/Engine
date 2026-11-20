<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
use App\Modules\Strategy\Http\Resources\StrategyPresenter;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Services\AngleService;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use App\Modules\Strategy\Services\StrategyText;
use App\Modules\Strategy\StrategyLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** Add, edit and remove the angles of a persona. Same order as the other strategy controllers. */
final class AngleController
{
    use ResolvesStrategyRoute;

    public function __construct(private readonly AngleService $angles) {}

    public function store(Request $request, Agency $agency, Brand $brand, string $wave, string $persona): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('update', WavePersona::class);

        /** @var array{usp: string, explanation: string} $data */
        $data = Validator::make(StrategyText::cleanInput($request->all(), ['explanation']), [
            'usp' => ['required', 'string', 'size:26'],
            'explanation' => ['required', 'string', 'max:'.StrategyLimits::ANGLE_EXPLANATION],
        ])->validate();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $angle = $this->angles->add($brand, $model, $persona, $actor, $data['usp'], $data['explanation']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => StrategyPresenter::angle($angle)], Response::HTTP_CREATED);
    }

    public function update(Request $request, Agency $agency, Brand $brand, string $wave, string $persona, string $angle): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('update', WavePersona::class);

        /** @var array{explanation: string} $data */
        $data = Validator::make(StrategyText::cleanInput($request->all(), ['explanation']), [
            'explanation' => ['required', 'string', 'max:'.StrategyLimits::ANGLE_EXPLANATION],
        ])->validate();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $updated = $this->angles->update($brand, $model, $persona, $angle, $actor, $data['explanation']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => StrategyPresenter::angle($updated)]);
    }

    /** PL-2.2 R2: convert a hand-written angle to the synced one of its Priority or Support cell. Any role. */
    public function useMatrixLine(Request $request, Agency $agency, Brand $brand, string $wave, string $persona, string $angle): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('update', WavePersona::class);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $converted = $this->angles->useMatrixLine($brand, $model, $persona, $angle, $actor);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => StrategyPresenter::angle($converted)]);
    }

    public function destroy(Request $request, Agency $agency, Brand $brand, string $wave, string $persona, string $angle): JsonResponse|Response
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('delete', WavePersona::class);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->angles->remove($brand, $model, $persona, $angle, $actor);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->noContent();
    }
}
