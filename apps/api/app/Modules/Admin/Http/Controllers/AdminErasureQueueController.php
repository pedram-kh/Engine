<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Admin\Http\Requests\ErasureHoldRequest;
use App\Modules\Erasure\Hold\ErasureHold;
use App\Modules\Erasure\Hold\ErasureHoldRefused;
use App\Modules\Erasure\Queue\ErasureQueue;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The admin erasure queue (AH-104c C2): who is waiting to be erased, and the legal hold.
 *
 *   GET  /api/v1/admin/compliance/erasure-queue?tab=&cursor=&per_page=
 *   POST /api/v1/admin/compliance/erasure-queue/{user}/hold      (reason required)
 *   POST /api/v1/admin/compliance/erasure-queue/{user}/release   (reason required)
 *
 * `{user}` is the USER ulid: the engine, the ledger, the runbook and `erasure:run` all key on it.
 * Platform admin only, behind the MFA gate (the route stack); cross-agency by design (a platform
 * compliance function), so no tenancy alias, like every admin surface.
 *
 * Nothing here erases anything: the Erase button and its job are C3. A row carries the person's
 * name and email while the account exists (the admin may see them) and the blocker CODES, never
 * the guards' own sentences (those carry counts and table names). The Erased tab is the ledger.
 */
final class AdminErasureQueueController
{
    public function __construct(
        private readonly ErasureQueue $queue,
        private readonly ErasureHold $hold,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $validated = $request->validate([
            'tab' => ['nullable', 'string', Rule::in(ErasureQueue::TABS)],
            'cursor' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]*$/'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tab = (string) ($validated['tab'] ?? ErasureQueue::PENDING);
        $perPage = (int) ($validated['per_page'] ?? 25);

        $page = $this->queue->page($tab, $validated['cursor'] ?? null, $perPage);

        return response()->json([
            'data' => $page['rows'],
            'meta' => [
                'tab' => $tab,
                'per_page' => $perPage,
                'next_cursor' => $page['next_cursor'],
                'counts' => $this->queue->counts(),
            ],
        ]);
    }

    public function hold(ErasureHoldRequest $request, string $user): JsonResponse
    {
        return $this->change($request, $user, 'hold');
    }

    public function release(ErasureHoldRequest $request, string $user): JsonResponse
    {
        return $this->change($request, $user, 'release');
    }

    private function change(ErasureHoldRequest $request, string $ulid, string $verb): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        /** @var User $admin */
        $admin = $request->user();

        /** @var User|null $subject */
        $subject = User::query()->withTrashed()->where('ulid', strtoupper($ulid))->first();
        if ($subject === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        try {
            $changed = $verb === 'hold'
                ? $this->hold->hold($subject, $admin, $request->reason())
                : $this->hold->release($subject, $admin, $request->reason());
        } catch (ErasureHoldRefused $e) {
            return $e->reasonCode === ErasureHoldRefused::CLAIMED
                ? ErrorResponse::single($request, Response::HTTP_CONFLICT, 'admin.erasure.hold.claimed', $e->getMessage())
                : ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'admin.erasure.hold.not_available', $e->getMessage());
        }

        return response()->json([
            'data' => $this->queue->rowFor($subject->refresh()),
            'meta' => ['changed' => $changed],
        ]);
    }

    private function authorizePlatformAdmin(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }
        if ($user->type !== UserType::PlatformAdmin) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }
}
