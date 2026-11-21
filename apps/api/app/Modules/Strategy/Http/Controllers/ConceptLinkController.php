<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
use App\Modules\Strategy\Http\Resources\ConceptPresenter;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Services\ConceptLinkService;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use App\Modules\Strategy\Services\StrategyText;
use App\Modules\Strategy\StrategyLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Reference links of a concept (PL-2.3). The URL is checked as text and stored; the server never opens it.
 */
final class ConceptLinkController
{
    use ResolvesStrategyRoute;

    public function __construct(private readonly ConceptLinkService $links) {}

    public function store(Request $request, Agency $agency, Brand $brand, string $wave, string $concept): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageReferences', WaveConcept::class);

        /** @var array{url: string, title: string} $data */
        $data = Validator::make(StrategyText::cleanInput($request->all(), ['url', 'title']), [
            'url' => ['required', 'string', 'max:'.StrategyLimits::LINK_URL],
            'title' => ['required', 'string', 'max:'.StrategyLimits::LINK_TITLE],
        ])->validate();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $out = $this->links->add($brand, $model, $concept, $actor, $data['url'], $data['title']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ConceptPresenter::link($out['link']), 'meta' => ['reset_to_awaiting' => $out['reset_to_awaiting']]], Response::HTTP_CREATED);
    }

    public function update(Request $request, Agency $agency, Brand $brand, string $wave, string $concept, string $link): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageReferences', WaveConcept::class);

        /** @var array<string, mixed> $data */
        $data = Validator::make(StrategyText::cleanInput($request->all(), ['url', 'title']), [
            'url' => ['sometimes', 'string', 'max:'.StrategyLimits::LINK_URL],
            'title' => ['sometimes', 'string', 'max:'.StrategyLimits::LINK_TITLE],
        ])->validate();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $out = $this->links->update($brand, $model, $concept, $link, $actor, $data);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ConceptPresenter::link($out['link']), 'meta' => ['reset_to_awaiting' => $out['reset_to_awaiting']]]);
    }

    public function destroy(Request $request, Agency $agency, Brand $brand, string $wave, string $concept, string $link): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageReferences', WaveConcept::class);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $reset = $this->links->remove($brand, $model, $concept, $link, $actor);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['meta' => ['reset_to_awaiting' => $reset]]);
    }
}
