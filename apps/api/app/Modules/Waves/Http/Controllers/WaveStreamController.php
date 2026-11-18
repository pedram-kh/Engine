<?php

declare(strict_types=1);

namespace App\Modules\Waves\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Enums\WaveStream;
use App\Modules\Waves\Http\Controllers\Concerns\ResolvesWaveRoute;
use App\Modules\Waves\Models\WaveStep;
use App\Modules\Waves\Services\WaveRuleViolation;
use App\Modules\Waves\Services\WaveStepService;
use App\Modules\Waves\Steps\WaveTrackerPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Remove a whole stream and add a removed one back (PL-1.3, ruling NEW). Admin and manager only. UGC video cannot be
 * removed (`stream.not_removable`). Both answer with the full tracker so the page redraws from one payload.
 */
final class WaveStreamController
{
    use ResolvesWaveRoute;

    public function __construct(private readonly WaveStepService $steps) {}

    public function destroy(Request $request, Agency $agency, Brand $brand, string $wave, string $stream): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageStreams', WaveStep::class);
        $enum = WaveStream::tryFrom($stream) ?? abort(Response::HTTP_NOT_FOUND);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->steps->removeStream($brand, $model, $enum, $actor);
        } catch (WaveRuleViolation $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, $e->errorCode, $e->getMessage());
        }

        return response()->json(['data' => WaveTrackerPresenter::tracker($model->refresh(), $actor)]);
    }

    public function store(Request $request, Agency $agency, Brand $brand, string $wave, string $stream): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageStreams', WaveStep::class);
        $enum = WaveStream::tryFrom($stream) ?? abort(Response::HTTP_NOT_FOUND);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->steps->addStream($brand, $model, $enum, $actor);
        } catch (WaveRuleViolation $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, $e->errorCode, $e->getMessage());
        }

        return response()->json(['data' => WaveTrackerPresenter::tracker($model->refresh(), $actor)]);
    }
}
