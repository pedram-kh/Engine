<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Controllers\Concerns;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Strategy\Features\StrategyEnabled;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use App\Modules\Waves\Models\Wave;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The first three lines of every strategy route (plan section 3.1), in this order: BOTH flags (404 `not_found`, before
 * the policy and before the body is read), the brand ownership check (404, never 403), then the wave lookup by ulid in
 * this agency and brand (a soft-deleted or foreign wave is a 404). Cards are then looked up THROUGH the wave.
 */
trait ResolvesStrategyRoute
{
    private function flagOff(Request $request): ?JsonResponse
    {
        if (StrategyEnabled::active()) {
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

    private function refuse(Request $request, StrategyRuleViolation $e): JsonResponse
    {
        return ErrorResponse::single($request, $e->status, $e->errorCode, $e->getMessage(), meta: $e->meta);
    }
}
