<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\ShotType;
use App\Modules\Strategy\Features\CreatorsScriptsEnabled;
use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
use App\Modules\Strategy\Http\Resources\ScriptPresenter;
use App\Modules\Strategy\Models\WaveScript;
use App\Modules\Strategy\Services\PersonaService;
use App\Modules\Strategy\Services\ScriptHookService;
use App\Modules\Strategy\Services\ScriptService;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use App\Modules\Strategy\Services\StrategyText;
use App\Modules\Strategy\StrategyLimits;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * PL-3.2a — the scripts of a wave (step 6 of the Strategy workspace): the rail, a script's brief and hooks, the archived list.
 * Order in every action: ALL THREE flags (404 `not_found`, before the policy and before the body is read), the brand (404), the
 * wave (404), the policy, validation, the service. `{script}` and `{hook}` are plain ulids looked up THROUGH the wave in the
 * services (a script of another wave or agency is a 404).
 */
final class ScriptController
{
    use ResolvesStrategyRoute;

    public function __construct(
        private readonly ScriptService $scripts,
        private readonly ScriptHookService $hooks,
    ) {}

    /** The step state and, with `?market=`, that market's rail. */
    public function index(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WaveScript::class);

        try {
            $market = $this->marketParam($request, $model);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::overview($model, $market)]);
    }

    /** The archived scripts of one market. */
    public function archived(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WaveScript::class);

        try {
            $market = $this->marketParam($request, $model);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }
        if ($market === null) {
            return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
        }

        return response()->json(['data' => ScriptPresenter::archived($model, $market)]);
    }

    public function show(Request $request, Agency $agency, Brand $brand, string $wave, string $script): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WaveScript::class);

        $row = WaveScript::query()->where('wave_id', $model->id)->where('ulid', $script)->whereNull('archived_at')->first();
        if ($row === null) {
            return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
        }

        return response()->json(['data' => ScriptPresenter::script($row)]);
    }

    public function store(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);

        /** @var array{slot: string} $data */
        $data = Validator::make($request->all(), ['slot' => ['required', 'string', 'max:26']])->validate();
        /** @var User $actor */
        $actor = $request->user();

        try {
            $script = $this->scripts->create($brand, $model, $actor, $data['slot']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($script)], Response::HTTP_CREATED);
    }

    public function brief(Request $request, Agency $agency, Brand $brand, string $wave, string $script): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);

        $input = StrategyText::cleanInput($request->all(), ['angle']);
        /** @var array{angle?: string|null, duration_seconds?: int, revision: int} $data */
        $data = Validator::make($input, [
            'angle' => ['sometimes', 'nullable', 'string', 'max:'.StrategyLimits::SCRIPT_ANGLE],
            'duration_seconds' => ['sometimes', 'integer', Rule::in(StrategyLimits::SCRIPT_DURATIONS)],
            'revision' => ['required', 'integer', 'min:1'],
        ])->validate();
        $changes = array_intersect_key($data, ['angle' => 1, 'duration_seconds' => 1]);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $row = $this->scripts->updateBrief($brand, $model, $actor, $script, $changes, (int) $data['revision']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($row)]);
    }

    public function destroy(Request $request, Agency $agency, Brand $brand, string $wave, string $script): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->scripts->delete($brand, $model, $actor, $script);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::overview($model, null)]);
    }

    public function destroyArchived(Request $request, Agency $agency, Brand $brand, string $wave, string $script): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('deleteScriptsInUse', WaveScript::class);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->scripts->deleteArchived($brand, $model, $actor, $script);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::overview($model, null)]);
    }

    public function hookStore(Request $request, Agency $agency, Brand $brand, string $wave, string $script): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $row = $this->hooks->add($brand, $model, $actor, $script);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($row)], Response::HTTP_CREATED);
    }

    public function hookUpdate(Request $request, Agency $agency, Brand $brand, string $wave, string $script, string $hook): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);

        $input = StrategyText::cleanInput($request->all(), ['visual_description', 'shot_type']);
        if (isset($input['texts']) && is_array($input['texts'])) {
            foreach ($input['texts'] as $field => $languages) {
                if (is_array($languages)) {
                    foreach ($languages as $language => $body) {
                        $input['texts'][$field][$language] = is_string($body) || $body === null ? StrategyText::clean($body) : $body;
                    }
                }
            }
        }
        /** @var array{shot_type?: string|null, visual_description?: string|null, texts?: array<string, array<string, string|null>>, revision: int} $data */
        $data = Validator::make($input, [
            'shot_type' => ['sometimes', 'nullable', 'string', Rule::in(ShotType::values())],
            'visual_description' => ['sometimes', 'nullable', 'string', 'max:'.StrategyLimits::HOOK_VISUAL],
            'texts' => ['sometimes', 'array'],
            'texts.audio' => ['sometimes', 'array'],
            'texts.audio.*' => ['nullable', 'string', 'max:'.StrategyLimits::HOOK_AUDIO],
            'texts.overlay' => ['sometimes', 'array'],
            'texts.overlay.*' => ['nullable', 'string', 'max:'.StrategyLimits::HOOK_OVERLAY],
            'revision' => ['required', 'integer', 'min:1'],
        ])->validate();
        $changes = array_intersect_key($data, ['shot_type' => 1, 'visual_description' => 1, 'texts' => 1]);
        if (isset($changes['texts'])) {
            $changes['texts'] = array_intersect_key($changes['texts'], ['audio' => 1, 'overlay' => 1]);
        }
        /** @var User $actor */
        $actor = $request->user();

        try {
            $row = $this->hooks->update($brand, $model, $actor, $script, $hook, $changes, (int) $data['revision']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($row)]);
    }

    public function hookDuplicate(Request $request, Agency $agency, Brand $brand, string $wave, string $script, string $hook): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $row = $this->hooks->duplicate($brand, $model, $actor, $script, $hook);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($row)], Response::HTTP_CREATED);
    }

    public function hookDestroy(Request $request, Agency $agency, Brand $brand, string $wave, string $script, string $hook): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $row = $this->hooks->delete($brand, $model, $actor, $script, $hook);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($row)]);
    }

    /** `?market=<ulid>`: a market of this wave, or none when it is optional and absent. */
    private function marketParam(Request $request, Wave $wave): ?WaveMarket
    {
        $ulid = $request->query('market');
        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        return PersonaService::assertMarketInWave($wave, $ulid);
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
