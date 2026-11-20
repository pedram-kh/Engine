<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Http\Controllers;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\PersonaPronoun;
use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
use App\Modules\Strategy\Http\Resources\StrategyPresenter;
use App\Modules\Strategy\Models\WavePersona;
use App\Modules\Strategy\Services\PersonaService;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use App\Modules\Strategy\Services\StrategyText;
use App\Modules\Strategy\StrategyLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * List, create, change, delete and copy personas. Order in every action: flags, brand, wave (404), policy, validation,
 * service. A PATCH carries only the fields that changed; the market of a persona never changes.
 */
final class PersonaController
{
    use ResolvesStrategyRoute;

    private const TEXT_KEYS = ['code', 'title', 'name', 'summary', 'core_job', 'need'];

    private const LIST_KEYS = ['drivers', 'goals', 'self_talk', 'barriers'];

    public function __construct(private readonly PersonaService $personas) {}

    public function index(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WavePersona::class);

        $market = $request->query('market');
        if ($market !== null && ! is_string($market)) {
            return $this->refuse($request, StrategyRuleViolation::personaMarketNotInWave('?'));
        }

        try {
            $list = $this->personas->list($model, $market);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => $list->map(static fn (WavePersona $p): array => StrategyPresenter::persona($p))->all()]);
    }

    public function store(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('create', WavePersona::class);

        $data = $this->validated($request, creating: true);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $persona = $this->personas->create($brand, $model, $actor, $data);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => StrategyPresenter::persona($persona->load(['angles.usp', 'cells.usp']))], Response::HTTP_CREATED);
    }

    public function update(Request $request, Agency $agency, Brand $brand, string $wave, string $persona): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('update', WavePersona::class);

        $data = $this->validated($request, creating: false);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $updated = $this->personas->update($brand, $model, $persona, $actor, $data);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => StrategyPresenter::persona($updated->load(['angles.usp', 'cells.usp']))]);
    }

    public function destroy(Request $request, Agency $agency, Brand $brand, string $wave, string $persona): JsonResponse|Response
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('delete', WavePersona::class);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->personas->delete($brand, $model, $persona, $actor);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->noContent();
    }

    public function copy(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('create', WavePersona::class);

        /** @var array{from_market: string, to_market: string, persona_ids?: list<string>|null} $data */
        $data = Validator::make($request->all(), [
            'from_market' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'to_market' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'persona_ids' => ['sometimes', 'nullable', 'array', 'min:1', 'max:'.StrategyLimits::PERSONA_MAX],
            'persona_ids.*' => ['required', 'string', 'size:26'],
        ])->validate();

        /** @var User $actor */
        $actor = $request->user();

        try {
            $created = $this->personas->copy($brand, $model, $actor, $data['from_market'], $data['to_market'], $data['persona_ids'] ?? null);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(
            [
                'data' => $created['personas']->map(static fn (WavePersona $p): array => StrategyPresenter::persona($p))->all(),
                'meta' => ['synced_angles_skipped' => $created['synced_skipped']],
            ],
            Response::HTTP_CREATED,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';

        $input = StrategyText::cleanInput($request->all(), self::TEXT_KEYS, self::LIST_KEYS);
        if (isset($input['code']) && is_string($input['code'])) {
            $input['code'] = strtoupper($input['code']);
        }

        $list = ['sometimes', 'nullable', 'array', 'max:'.StrategyLimits::LIST_ITEMS];

        /** @var array<string, mixed> $data */
        $data = Validator::make($input, [
            'market' => $creating ? ['required', 'string', 'regex:/^[A-Z]{2}$/'] : ['prohibited'],
            'code' => $creating
                ? ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9]{'.StrategyLimits::PERSONA_CODE_MIN.','.StrategyLimits::PERSONA_CODE_MAX.'}$/']
                : ['sometimes', 'string', 'regex:/^[A-Z0-9]{'.StrategyLimits::PERSONA_CODE_MIN.','.StrategyLimits::PERSONA_CODE_MAX.'}$/'],
            'title' => [$presence, 'string', 'max:'.StrategyLimits::PERSONA_TITLE],
            'name' => [$presence, 'string', 'max:'.StrategyLimits::PERSONA_NAME],
            'pronoun' => ['sometimes', 'string', Rule::enum(PersonaPronoun::class)],
            'summary' => ['sometimes', 'nullable', 'string', 'max:'.StrategyLimits::PERSONA_SUMMARY],
            'core_job' => ['sometimes', 'nullable', 'string', 'max:'.StrategyLimits::PERSONA_PARAGRAPH],
            'need' => ['sometimes', 'nullable', 'string', 'max:'.StrategyLimits::PERSONA_PARAGRAPH],
            'drivers' => $list,
            'goals' => $list,
            'self_talk' => $list,
            'barriers' => $list,
            'drivers.*' => ['string', 'max:'.StrategyLimits::LIST_ITEM],
            'goals.*' => ['string', 'max:'.StrategyLimits::LIST_ITEM],
            'self_talk.*' => ['string', 'max:'.StrategyLimits::LIST_ITEM],
            'barriers.*' => ['string', 'max:'.StrategyLimits::LIST_ITEM],
        ])->validate();

        if (isset($data['pronoun']) && is_string($data['pronoun'])) {
            $data['pronoun'] = PersonaPronoun::from($data['pronoun']);
        }

        // An empty list is stored as an empty list (the presenter shows [] either way).
        return $data;
    }
}
