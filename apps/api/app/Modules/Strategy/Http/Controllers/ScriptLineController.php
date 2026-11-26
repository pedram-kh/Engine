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
use App\Modules\Strategy\Services\ScriptHookService;
use App\Modules\Strategy\Services\ScriptLineService;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use App\Modules\Strategy\Services\StrategyText;
use App\Modules\Strategy\StrategyLimits;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * PL-3.2b: the lines of a script, their B-roll rows and the order of hooks. Order in every action: ALL THREE flags (404
 * `not_found`, before the policy and before the body is read), the brand (404), the wave (404), the policy, validation, the
 * service. `{script}`, `{line}` and `{broll}` are plain ulids looked up THROUGH the wave in the services.
 */
final class ScriptLineController
{
    use ResolvesStrategyRoute;

    public function __construct(
        private readonly ScriptLineService $lines,
        private readonly ScriptHookService $hooks,
    ) {}

    public function store(Request $request, Agency $agency, Brand $brand, string $wave, string $script): JsonResponse
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
            $row = $this->lines->add($brand, $model, $actor, $script);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($row)], Response::HTTP_CREATED);
    }

    /** The block save of ONE line: the spoken text per language, the CTA flag and the fields of its B-roll rows. */
    public function update(Request $request, Agency $agency, Brand $brand, string $wave, string $script, string $line): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);
        $input = $request->all();
        if (isset($input['texts']) && is_array($input['texts'])) {
            foreach ($input['texts'] as $language => $body) {
                $input['texts'][$language] = is_string($body) || $body === null ? StrategyText::clean($body) : $body;
            }
        }
        if (isset($input['broll']) && is_array($input['broll'])) {
            foreach ($input['broll'] as $ulid => $fields) {
                if (is_array($fields)) {
                    $input['broll'][$ulid] = StrategyText::cleanInput($fields, ['description', 'shot_type']);
                }
            }
        }
        /** @var array{texts?: array<string, string|null>, cta?: bool, broll?: array<string, array{shot_type?: string|null, description?: string|null}>, revision: int} $data */
        $data = Validator::make($input, [
            'texts' => ['sometimes', 'array', $this->languageKeys(...)],
            'texts.*' => ['nullable', 'string', 'max:'.StrategyLimits::LINE_TEXT],
            'cta' => ['sometimes', 'boolean'],
            'broll' => ['sometimes', 'array'],
            'broll.*' => ['array'],
            'broll.*.shot_type' => ['sometimes', 'nullable', 'string', Rule::in(ShotType::values())],
            'broll.*.description' => ['sometimes', 'nullable', 'string', 'max:'.StrategyLimits::BROLL_DESCRIPTION],
            'revision' => ['required', 'integer', 'min:1'],
        ])->validate();
        $changes = array_intersect_key($data, ['texts' => 1, 'cta' => 1, 'broll' => 1]);
        if (isset($changes['cta'])) {
            $changes['cta'] = (bool) $changes['cta'];
        }
        if (isset($changes['broll'])) {
            foreach ($changes['broll'] as $ulid => $fields) {
                $changes['broll'][$ulid] = array_intersect_key($fields, ['shot_type' => 1, 'description' => 1]);
            }
        }
        /** @var User $actor */
        $actor = $request->user();

        try {
            $row = $this->lines->update($brand, $model, $actor, $script, $line, $changes, (int) $data['revision']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($row)]);
    }

    public function duplicate(Request $request, Agency $agency, Brand $brand, string $wave, string $script, string $line): JsonResponse
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
            $row = $this->lines->duplicate($brand, $model, $actor, $script, $line);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($row)], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, Agency $agency, Brand $brand, string $wave, string $script, string $line): JsonResponse
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
            $row = $this->lines->delete($brand, $model, $actor, $script, $line);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($row)]);
    }

    /** The complete ordered list of the script's lines. */
    public function order(Request $request, Agency $agency, Brand $brand, string $wave, string $script): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);
        $ids = $this->ids($request);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $row = $this->lines->reorder($brand, $model, $actor, $script, $ids);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($row)]);
    }

    /** The complete ordered list of the script's hooks. */
    public function hookOrder(Request $request, Agency $agency, Brand $brand, string $wave, string $script): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);
        $ids = $this->ids($request);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $row = $this->hooks->reorder($brand, $model, $actor, $script, $ids);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($row)]);
    }

    public function brollStore(Request $request, Agency $agency, Brand $brand, string $wave, string $script, string $line): JsonResponse
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
            $row = $this->lines->brollAdd($brand, $model, $actor, $script, $line);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($row)], Response::HTTP_CREATED);
    }

    public function brollDestroy(Request $request, Agency $agency, Brand $brand, string $wave, string $script, string $line, string $broll): JsonResponse
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
            $row = $this->lines->brollDelete($brand, $model, $actor, $script, $line, $broll);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($row)]);
    }

    /** The complete ordered list of the B-roll rows of one line. */
    public function brollOrder(Request $request, Agency $agency, Brand $brand, string $wave, string $script, string $line): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('manageScripts', WaveScript::class);
        $ids = $this->ids($request);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $row = $this->lines->brollReorder($brand, $model, $actor, $script, $line, $ids);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => ScriptPresenter::script($row)]);
    }

    /** @return list<string> */
    private function ids(Request $request): array
    {
        /** @var array{ids: list<string>} $data */
        $data = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'max:'.(StrategyLimits::LINES_PER_SCRIPT + StrategyLimits::HOOKS_PER_SCRIPT)],
            'ids.*' => ['required', 'string', 'size:26'],
        ])->validate();

        return array_values($data['ids']);
    }

    /** The keys of a `texts` map are two-letter language codes. */
    private function languageKeys(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (array_keys(is_array($value) ? $value : []) as $key) {
            if (! is_string($key) || preg_match('/^[a-z]{2}$/', $key) !== 1) {
                $fail('A text language must be a two-letter code.');

                return;
            }
        }
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
