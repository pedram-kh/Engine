<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\FunnelStage;
use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
use App\Modules\Strategy\Http\Resources\ConceptPresenter;
use App\Modules\Strategy\Models\WaveConcept;
use App\Modules\Strategy\Models\WaveConceptDecision;
use App\Modules\Strategy\Services\ConceptDecisionService;
use App\Modules\Strategy\Services\ConceptService;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use App\Modules\Strategy\Services\StrategyText;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Concepts of a market (PL-2.3). Order in every action: flags, brand, wave (404), policy, validation, service. Every
 * answer is the one concept shape, so the web replaces a card from any response.
 */
final class ConceptController
{
    use ResolvesStrategyRoute;

    private const TEXT_KEYS = ['title', 'funnel_note', 'product', 'angle', 'primary_message', 'hypothesis', 'hook', 'format_visual', 'payoff', 'cta'];

    public function __construct(private readonly ConceptService $concepts, private readonly ConceptDecisionService $decisions) {}

    public function index(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WaveConcept::class);

        $market = $request->query('market');
        if (! is_string($market) || preg_match('/^[A-Z]{2}$/', $market) !== 1) {
            return $this->refuse($request, StrategyRuleViolation::conceptMarketRequired());
        }

        try {
            $list = $this->concepts->list($model, $market);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        $summary = ConceptService::summary($model);
        $counts = collect($summary['by_market'])->firstWhere('market', $market);

        return response()->json([
            'data' => $list->map(fn (WaveConcept $c): array => ConceptPresenter::concept($c, $model, $agency->ulid, $brand->ulid))->all(),
            'meta' => ['market' => $counts],
        ]);
    }

    public function store(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('create', WaveConcept::class);

        $data = $this->validated($request, creating: true);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $concept = $this->concepts->create($brand, $model, $actor, $data);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => $this->one($concept, $model, $agency, $brand)], Response::HTTP_CREATED);
    }

    public function update(Request $request, Agency $agency, Brand $brand, string $wave, string $concept): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('update', WaveConcept::class);

        $data = $this->validated($request, creating: false);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $result = $this->concepts->update($brand, $model, $concept, $actor, $data);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => $this->one($result['concept'], $model, $agency, $brand, $result['reset_to_awaiting'])]);
    }

    public function duplicate(Request $request, Agency $agency, Brand $brand, string $wave, string $concept): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('duplicate', WaveConcept::class);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $copy = $this->concepts->duplicate($brand, $model, $concept, $actor);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => $this->one($copy, $model, $agency, $brand)], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, Agency $agency, Brand $brand, string $wave, string $concept): JsonResponse|Response
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('delete', WaveConcept::class);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->concepts->delete($brand, $model, $concept, $actor, Gate::forUser($actor)->allows('deleteDecided', WaveConcept::class));
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->noContent();
    }

    public function decide(Request $request, Agency $agency, Brand $brand, string $wave, string $concept): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('recordDecision', WaveConcept::class);

        $input = StrategyText::cleanInput($request->all(), ['client_name', 'note', 'comment']);

        /** @var array{status: string, client_name: string, note?: string|null, comment?: string|null} $data */
        $data = Validator::make($input, [
            'status' => ['required', 'string', Rule::in(['approved', 'changes_requested'])],
            'client_name' => ['required', 'string', 'max:80'],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.StrategyLimits::DECISION_NOTE],
            'comment' => ['sometimes', 'nullable', 'string', 'max:'.StrategyLimits::DECISION_COMMENT],
        ])->validate();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->decisions->record($brand, $model, $concept, $actor, $data);
            $fresh = $this->concepts->find($model, $concept);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => $this->one($fresh, $model, $agency, $brand)]);
    }

    public function decisionHistory(Request $request, Agency $agency, Brand $brand, string $wave, string $concept): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WaveConcept::class);

        try {
            /** @var Collection<int, WaveConceptDecision> $history */
            $history = $this->decisions->history($model, $concept);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => $history->map(static fn (WaveConceptDecision $d): array => ConceptPresenter::decision($d))->all()]);
    }

    /** @return array<string, mixed> */
    private function one(WaveConcept $concept, Wave $wave, Agency $agency, Brand $brand, bool $reset = false): array
    {
        $hydrated = ConceptService::hydrate(new \Illuminate\Database\Eloquent\Collection([$concept->refresh()]))->first();

        return ConceptPresenter::concept($hydrated ?? $concept, $wave, $agency->ulid, $brand->ulid, $reset);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $input = StrategyText::cleanInput($request->all(), self::TEXT_KEYS, ['key_points']);
        $presence = $creating ? 'required' : 'sometimes';
        $one = static fn (int $max): array => ['sometimes', 'nullable', 'string', 'max:'.$max];

        /** @var array<string, mixed> $data */
        $data = Validator::make($input, [
            'market' => $creating ? ['required', 'string', 'regex:/^[A-Z]{2}$/'] : ['prohibited'],
            'persona' => [$presence, 'string', 'size:26'],
            'usp_mode' => ['sometimes', 'string', Rule::in(['keep', 'refill'])],
            'usps' => ['sometimes', 'array', 'max:'.StrategyLimits::USP_MAX],
            'usps.*' => ['required', 'string', 'size:26', 'distinct'],
            'title' => [$presence, 'string', 'max:'.StrategyLimits::CONCEPT_TITLE],
            'funnel_stage' => ['sometimes', 'nullable', 'string', Rule::enum(FunnelStage::class)],
            'funnel_note' => $one(StrategyLimits::CONCEPT_FUNNEL_NOTE),
            'product' => $one(StrategyLimits::CONCEPT_PRODUCT),
            'angle' => $one(StrategyLimits::CONCEPT_ANGLE),
            'primary_message' => $one(StrategyLimits::CONCEPT_PRIMARY_MESSAGE),
            'hypothesis' => $one(StrategyLimits::CONCEPT_HYPOTHESIS),
            'hook' => $one(StrategyLimits::CONCEPT_HOOK),
            'format_visual' => $one(StrategyLimits::CONCEPT_FORMAT_VISUAL),
            'payoff' => $one(StrategyLimits::CONCEPT_PAYOFF),
            'cta' => $one(StrategyLimits::CONCEPT_CTA),
            'key_points' => ['sometimes', 'nullable', 'array', 'max:'.StrategyLimits::CONCEPT_KEY_POINTS],
            'key_points.*' => ['string', 'max:'.StrategyLimits::CONCEPT_KEY_POINT],
        ])->validate();

        return $data;
    }
}
