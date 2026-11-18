<?php

declare(strict_types=1);

namespace App\Modules\Waves\Http\Controllers\Concerns;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Waves\Features\WavesEnabled;
use App\Modules\Waves\Models\Wave;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Pennant\Feature;

/**
 * The first three lines of every wave route (the WaveController order): the `waves_enabled` flag (404 `not_found`,
 * before the policy and before the body is read), the brand ownership check (404, never 403), then the wave lookup
 * by ulid in this agency and brand (a soft-deleted or foreign wave is a 404).
 */
trait ResolvesWaveRoute
{
    private function flagOff(Request $request): ?JsonResponse
    {
        if (Feature::active(WavesEnabled::NAME)) {
            return null;
        }

        return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
    }

    private function assertBrand(Brand $brand, Agency $agency): void
    {
        if ($brand->agency_id !== $agency->id) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }

    private function findWave(string $ulid, Agency $agency, Brand $brand): Wave
    {
        return Wave::query()
            ->where('ulid', $ulid)
            ->where('agency_id', $agency->id)
            ->where('brand_id', $brand->id)
            ->firstOrFail();
    }
}
