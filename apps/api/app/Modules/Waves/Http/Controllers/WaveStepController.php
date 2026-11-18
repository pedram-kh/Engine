<?php

declare(strict_types=1);

namespace App\Modules\Waves\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Enums\WaveStepStatus;
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
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Add, change and remove a step (PL-1.3). Order in every action: flag, brand, wave (404), policy, validation,
 * service. A PATCH that mixes fields a staff user may change with fields only admin and manager may change
 * (`name`, `stream`) is refused as a whole (403), never half applied.
 */
final class WaveStepController
{
    use ResolvesWaveRoute;

    public function __construct(private readonly WaveStepService $steps) {}

    public function store(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('add', WaveStep::class);

        /** @var array<string, mixed> $data */
        $data = Validator::make($request->all(), [
            'stream' => ['required', 'string', Rule::enum(WaveStream::class)],
            'name' => ['required', 'string', 'min:1', 'max:160'],
            'owner' => ['sometimes', 'nullable', 'string', 'size:26'],
            'owner_client' => ['sometimes', 'boolean'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ])->validate();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $step = $this->steps->add($brand, $model, $actor, $data);
        } catch (WaveRuleViolation $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, $e->errorCode, $e->getMessage());
        }

        return response()->json(['data' => WaveTrackerPresenter::stepResponse($step, $actor)], Response::HTTP_CREATED);
    }

    public function update(Request $request, Agency $agency, Brand $brand, string $wave, string $step): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('update', WaveStep::class);

        // Rename and move are admin and manager only; any such key from a staff user refuses the whole request.
        if ($request->hasAny(['name', 'stream'])) {
            Gate::authorize('rename', WaveStep::class);
        }

        /** @var array<string, mixed> $data */
        $data = Validator::make($request->all(), [
            'status' => ['sometimes', 'string', Rule::enum(WaveStepStatus::class)],
            'name' => ['sometimes', 'string', 'min:1', 'max:160'],
            'stream' => ['sometimes', 'string', Rule::enum(WaveStream::class)],
            'owner' => ['sometimes', 'nullable', 'string', 'size:26'],
            'owner_client' => ['sometimes', 'boolean'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ])->validate();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $updated = $this->steps->update($brand, $model, $step, $actor, $data);
        } catch (WaveRuleViolation $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, $e->errorCode, $e->getMessage());
        }

        return response()->json(['data' => WaveTrackerPresenter::stepResponse($updated, $actor)]);
    }

    public function destroy(Request $request, Agency $agency, Brand $brand, string $wave, string $step): JsonResponse|Response
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('remove', WaveStep::class);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->steps->remove($brand, $model, $step, $actor);
        } catch (WaveRuleViolation $e) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, $e->errorCode, $e->getMessage());
        }

        return response()->noContent();
    }
}
