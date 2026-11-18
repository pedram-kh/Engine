<?php

declare(strict_types=1);

namespace App\Modules\Waves\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Http\Controllers\Concerns\ResolvesWaveRoute;
use App\Modules\Waves\Models\WaveStep;
use App\Modules\Waves\Services\WaveStepProvisioner;
use App\Modules\Waves\Steps\WaveTrackerPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET brands/{brand}/waves/{wave}/tracker (PL-1.3). Provisions the default steps of a wave that has none yet
 * (the `BoardService::ensureBoard` precedent: an idempotent write on a GET, marker-guarded), then returns the
 * streams, the steps, the health and the six-step progress.
 */
final class WaveTrackerController
{
    use ResolvesWaveRoute;

    public function __construct(private readonly WaveStepProvisioner $provisioner) {}

    public function show(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WaveStep::class);

        /** @var User $viewer */
        $viewer = $request->user();
        $this->provisioner->ensure($model);

        return response()->json(['data' => WaveTrackerPresenter::tracker($model->refresh(), $viewer)]);
    }
}
