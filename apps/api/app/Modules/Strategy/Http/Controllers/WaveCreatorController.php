<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
use App\Modules\Strategy\Http\Resources\WaveCreatorPresenter;
use App\Modules\Strategy\Models\WaveCreator;
use App\Modules\Strategy\Services\PersonaService;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use App\Modules\Strategy\Services\StrategyText;
use App\Modules\Strategy\Services\WaveCreatorCandidates;
use App\Modules\Strategy\Services\WaveCreatorDecisionService;
use App\Modules\Strategy\Services\WaveCreatorService;
use App\Modules\Strategy\Services\WaveCreatorSource;
use App\Modules\Strategy\StrategyLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * PL-3.1 — the creators of a wave (step 5 of the Strategy workspace). Order in every action: ALL THREE flags (404 `not_found`,
 * before the policy and before the body is read), the brand (404), the wave (404), the policy, validation, the service.
 * `{slot}` is the wave-creator ulid, looked up THROUGH the wave in the service (a slot of another wave or agency is a 404).
 */
final class WaveCreatorController
{
    use ResolvesStrategyRoute;

    public function __construct(
        private readonly WaveCreatorService $creators,
        private readonly WaveCreatorDecisionService $decisions,
        private readonly WaveCreatorCandidates $candidates,
    ) {}

    public function index(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WaveCreator::class);

        return response()->json(['data' => WaveCreatorPresenter::show($model, $request)]);
    }

    public function candidates(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageCreators', WaveCreator::class);

        $campaign = WaveCreatorSource::campaign($model);
        if ($campaign === null) {
            return $this->refuse($request, StrategyRuleViolation::creatorCampaignRequired());
        }

        $marketUlid = $request->query('market');
        try {
            $market = is_string($marketUlid) && $marketUlid !== '' ? PersonaService::assertMarketInWave($model, $marketUlid) : null;
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json($this->candidates->page($model, $campaign, $market, $request));
    }

    public function store(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageCreators', WaveCreator::class);

        /** @var array{market: string, creators: list<string>, persona?: string|null} $data */
        $data = Validator::make($request->all(), [
            'market' => ['required', 'string', 'max:26'],
            'creators' => ['required', 'array', 'min:1', 'max:'.StrategyLimits::CREATORS_PER_MARKET],
            'creators.*' => ['required', 'string', 'max:26'],
            'persona' => ['sometimes', 'nullable', 'string', 'max:26'],
        ])->validate();
        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->creators->add($brand, $model, $actor, $data['market'], array_values($data['creators']), $data['persona'] ?? null);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => WaveCreatorPresenter::show($model, $request)], Response::HTTP_CREATED);
    }

    public function update(Request $request, Agency $agency, Brand $brand, string $wave, string $slot): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageCreators', WaveCreator::class);

        $input = $request->all();
        if (isset($input['slot_code']) && is_string($input['slot_code'])) {
            $input['slot_code'] = mb_strtoupper((string) StrategyText::clean($input['slot_code']));
        }
        /** @var array{slot_code?: string, persona?: string|null} $data */
        $data = Validator::make($input, [
            'slot_code' => ['sometimes', 'string', 'min:'.StrategyLimits::CREATOR_SLOT_CODE_MIN, 'max:'.StrategyLimits::CREATOR_SLOT_CODE_MAX, 'regex:/^[A-Z0-9][A-Z0-9 _-]*$/'],
            'persona' => ['sometimes', 'nullable', 'string', 'max:26'],
        ])->validate();
        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->creators->update($brand, $model, $actor, $slot, $data);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => WaveCreatorPresenter::show($model, $request)]);
    }

    public function replace(Request $request, Agency $agency, Brand $brand, string $wave, string $slot): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageCreators', WaveCreator::class);

        /** @var array{creator: string, scripts?: string} $data */
        $data = Validator::make($request->all(), [
            'creator' => ['required', 'string', 'max:26'],
            // PL-3.2a (Q8): what happens to the old creator's scripts. Absent = the default, carry over.
            'scripts' => ['sometimes', 'string', Rule::in([WaveCreatorService::CARRY_OVER, WaveCreatorService::ARCHIVE])],
        ])->validate();
        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->creators->replace($brand, $model, $actor, $slot, $data['creator'], $data['scripts'] ?? WaveCreatorService::CARRY_OVER);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => WaveCreatorPresenter::show($model, $request)]);
    }

    public function destroy(Request $request, Agency $agency, Brand $brand, string $wave, string $slot): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageCreators', WaveCreator::class);
        /** @var array{scripts?: string} $data */
        $data = Validator::make($request->all(), ['scripts' => ['sometimes', 'nullable', 'string', Rule::in([WaveCreatorService::ARCHIVE, WaveCreatorService::DELETE])]])->validate();
        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->creators->remove($brand, $model, $actor, $slot, $data['scripts'] ?? null);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => WaveCreatorPresenter::show($model, $request)]);
    }

    public function decisionHistory(Request $request, Agency $agency, Brand $brand, string $wave, string $slot): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WaveCreator::class);

        try {
            $rows = $this->decisions->history($model, $slot);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => $rows->map(static fn ($d): array => WaveCreatorPresenter::decision($d))->values()->all()]);
    }

    public function decide(Request $request, Agency $agency, Brand $brand, string $wave, string $slot): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('decideCreators', WaveCreator::class);

        $input = StrategyText::cleanInput($request->all(), ['client_name', 'note', 'comment']);

        /** @var array{status: string, client_name: string, note?: string|null, comment?: string|null} $data */
        $data = Validator::make($input, [
            'status' => ['required', 'string', Rule::in(['approved', 'suggest_another'])],
            'client_name' => ['required', 'string', 'max:'.StrategyLimits::CLIENT_NAME],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.StrategyLimits::DECISION_NOTE],
            'comment' => ['sometimes', 'nullable', 'string', 'max:'.StrategyLimits::DECISION_COMMENT],
        ])->validate();
        /** @var User $actor */
        $actor = $request->user();

        try {
            $result = $this->decisions->record($brand, $model, $actor, $slot, $data);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json([
            'data' => WaveCreatorPresenter::show($model, $request),
            'meta' => ['card_moved' => $result['board']['moved'], 'card_not_moved' => $result['board']['card_not_moved']],
        ], Response::HTTP_CREATED);
    }

    /** All three flags, read this flag FIRST (it short-circuits). */
    private function flagOff(Request $request): ?JsonResponse
    {
        if (CreatorsScriptsEnabled::active()) {
            return null;
        }

        return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
    }
}
