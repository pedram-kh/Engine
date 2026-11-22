<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Notes;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Brands\Models\Brand;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Http\Controllers\Concerns\ResolvesStrategyRoute;
use App\Modules\Strategy\Models\WaveStrategyNote;
use App\Modules\Strategy\Services\StrategyRuleViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Internal notes of a strategy step (PL-2.5, K4). Order in every action: flags, brand, wave (404), policy, validation,
 * service. Agency users only: a client or creator has no agency role and is refused by the policy.
 */
final class NoteController
{
    use ResolvesStrategyRoute;

    public function __construct(private readonly NoteService $notes) {}

    public function index(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('view', WaveStrategyNote::class);

        /** @var array<string, mixed> $data */
        $data = Validator::make($request->query(), [
            'step' => ['required', 'string', 'max:16'],
            'market' => ['sometimes', 'nullable', 'string', 'size:26'],
        ])->validate();
        /** @var User $viewer */
        $viewer = $request->user();

        try {
            $thread = $this->notes->thread($model, (string) $data['step'], $data['market'] ?? null);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['data' => NotePresenter::thread($thread, $viewer, $model->agency_id)]);
    }

    public function store(Request $request, Agency $agency, Brand $brand, string $wave): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('create', WaveStrategyNote::class);

        /** @var array<string, mixed> $data */
        $data = Validator::make($request->all(), [
            'step' => ['required', 'string', 'max:16'],
            'market' => ['sometimes', 'nullable', 'string', 'size:26'],
            'body' => ['required', 'string'],
        ])->validate();
        /** @var User $actor */
        $actor = $request->user();

        try {
            $note = $this->notes->create($brand, $model, $actor, (string) $data['step'], $data['market'] ?? null, (string) $data['body']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }
        $note->load('market');

        return response()->json(['data' => NotePresenter::one($note, $actor, true, true)], Response::HTTP_CREATED);
    }

    public function update(Request $request, Agency $agency, Brand $brand, string $wave, string $note): JsonResponse
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('update', WaveStrategyNote::class);

        /** @var array<string, mixed> $data */
        $data = Validator::make($request->all(), ['body' => ['required', 'string']])->validate();
        /** @var User $actor */
        $actor = $request->user();

        try {
            $updated = $this->notes->update($brand, $model, $actor, $note, (string) $data['body']);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }
        $updated->load('market');

        return response()->json(['data' => NotePresenter::one($updated, $actor, true, true)]);
    }

    public function destroy(Request $request, Agency $agency, Brand $brand, string $wave, string $note): JsonResponse|Response
    {
        if ($off = $this->flagOff($request)) {
            return $off;
        }
        $this->assertBrand($brand, $agency);
        $model = $this->findWave($wave, $agency, $brand);
        Gate::authorize('delete', WaveStrategyNote::class);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->notes->delete($brand, $model, $actor, $note);
        } catch (StrategyRuleViolation $e) {
            return $this->refuse($request, $e);
        }

        return response()->noContent();
    }
}
