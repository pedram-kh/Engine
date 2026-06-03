<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Modules\Creators\Http\Requests\ListAvailabilityBlocksRequest;
use App\Modules\Creators\Http\Requests\StoreAvailabilityBlockRequest;
use App\Modules\Creators\Http\Requests\UpdateAvailabilityBlockRequest;
use App\Modules\Creators\Http\Resources\AvailabilityOccurrenceResource;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorAvailabilityBlock;
use App\Modules\Creators\Services\Availability\AvailabilityExpansionService;
use App\Modules\Creators\Services\Availability\AvailabilityOccurrence;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Manual availability-block CRUD for the authenticated creator
 * (Sprint 5 Chunk A, D-a1).
 *
 *   GET    /api/v1/creators/me/availability            list (expanded window)
 *   POST   /api/v1/creators/me/availability            create
 *   PATCH  /api/v1/creators/me/availability/{block}    update
 *   DELETE /api/v1/creators/me/availability/{block}    delete
 *
 * Ownership is STRUCTURAL: every row is resolved through
 * $request->user()->creator->availabilityBlocks(), never from a path id.
 * A block belonging to another creator is simply not found in that
 * relation, so cross-creator access is impossible by construction
 * (mirrors CreatorWizardController::requireCreator()). Break-revert:
 * resolving the block globally by ULID would let one creator edit
 * another's blocks — the owner-only tests would fail.
 *
 * The list endpoint expands occurrences for a requested window via the
 * single {@see AvailabilityExpansionService} (D-a4) and returns the source
 * block's rule alongside each occurrence so the calendar can edit it.
 */
final class CreatorAvailabilityController
{
    /**
     * Default window when the caller sends no `from`/`to` — from the start
     * of today, looking 90 days ahead (a typical calendar planning horizon).
     */
    private const int DEFAULT_WINDOW_DAYS = 90;

    /**
     * Hard ceiling on the requested window span. Bounds recurrence
     * expansion so a pathological `?from=...&to=...` can't generate an
     * unbounded occurrence set.
     */
    private const int MAX_WINDOW_DAYS = 366;

    public function __construct(
        private readonly AvailabilityExpansionService $expansion,
    ) {}

    public function index(ListAvailabilityBlocksRequest $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        $from = $request->filled('from')
            ? CarbonImmutable::parse((string) $request->input('from'))
            : CarbonImmutable::now()->startOfDay();

        $to = $request->filled('to')
            ? CarbonImmutable::parse((string) $request->input('to'))
            : $from->addDays(self::DEFAULT_WINDOW_DAYS);

        // Clamp the span so recurrence expansion stays bounded.
        $maxTo = $from->addDays(self::MAX_WINDOW_DAYS);
        if ($to->greaterThan($maxTo)) {
            $to = $maxTo;
        }

        $occurrences = $this->expansion->expand($creator, $from, $to);

        return AvailabilityOccurrenceResource::collection($occurrences)
            ->additional([
                'meta' => [
                    'window' => [
                        'from' => $from->toIso8601String(),
                        'to' => $to->toIso8601String(),
                    ],
                ],
            ])
            ->response();
    }

    public function store(StoreAvailabilityBlockRequest $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        $block = $creator->availabilityBlocks()->create($this->attributesFrom($request));

        return $this->blockResponse($block, Response::HTTP_CREATED);
    }

    public function update(UpdateAvailabilityBlockRequest $request, string $block): JsonResponse
    {
        $creator = $this->requireCreator($request);

        $model = $this->resolveBlock($creator, $block);
        $model->update($this->attributesFrom($request));

        return $this->blockResponse($model->refresh(), Response::HTTP_OK);
    }

    public function destroy(Request $request, string $block): Response
    {
        $creator = $this->requireCreator($request);

        $this->resolveBlock($creator, $block)->delete();

        return response()->noContent();
    }

    /**
     * Map validated input to model attributes. The recurrence_rule is
     * persisted only on a recurring block; a non-recurring block always
     * stores null (so toggling recurrence off clears a stale rule).
     *
     * @return array<string, mixed>
     */
    private function attributesFrom(StoreAvailabilityBlockRequest $request): array
    {
        $isRecurring = $request->boolean('is_recurring');

        return [
            'starts_at' => CarbonImmutable::parse((string) $request->input('starts_at')),
            'ends_at' => CarbonImmutable::parse((string) $request->input('ends_at')),
            'is_all_day' => $request->boolean('is_all_day'),
            'block_type' => (string) $request->input('block_type'),
            'kind' => (string) $request->input('kind'),
            'reason' => $request->input('reason'),
            'is_recurring' => $isRecurring,
            'recurrence_rule' => $isRecurring ? (string) $request->input('recurrence_rule') : null,
        ];
    }

    /**
     * Resolve a block within the creator's OWN blocks. A non-owned ULID is
     * not in the relation, so firstOrFail() yields a 404 — the structural
     * owner-only guard.
     */
    private function resolveBlock(Creator $creator, string $ulid): CreatorAvailabilityBlock
    {
        return $creator->availabilityBlocks()
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    private function blockResponse(CreatorAvailabilityBlock $block, int $status): JsonResponse
    {
        // Present the stored block as its own canonical occurrence so the
        // create/update response shape matches a list item exactly.
        $occurrence = new AvailabilityOccurrence(
            $block,
            CarbonImmutable::instance($block->starts_at),
            CarbonImmutable::instance($block->ends_at),
        );

        return (new AvailabilityOccurrenceResource($occurrence))
            ->response()
            ->setStatusCode($status);
    }

    private function requireCreator(Request $request): Creator
    {
        /** @var User $user */
        $user = $request->user();
        $creator = $user->creator;

        if ($creator === null) {
            abort(response()->json([
                'errors' => [[
                    'status' => '404',
                    'code' => 'creator.not_found',
                    'detail' => 'No creator profile is associated with this user.',
                ]],
            ], 404));
        }

        return $creator;
    }
}
