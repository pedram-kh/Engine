<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Admin\Http\Requests\ErasureHoldRequest;
use App\Modules\Erasure\Erase\EraseInProgress;
use App\Modules\Erasure\Erase\EraseNotReady;
use App\Modules\Erasure\Erase\ErasePlanner;
use App\Modules\Erasure\Erase\EraseStarter;
use App\Modules\Erasure\Features\AccountErasureExecuteEnabled;
use App\Modules\Erasure\Hold\ErasureHold;
use App\Modules\Erasure\Hold\ErasureHoldRefused;
use App\Modules\Erasure\Queue\ErasureQueue;
use App\Modules\Erasure\Subject\SubjectNotErasable;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\SecondFactorOutcome;
use App\Modules\Identity\Services\SecondFactorStepUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

/**
 * The admin erasure queue (AH-104c C2): who is waiting to be erased, and the legal hold.
 *
 *   GET  /api/v1/admin/compliance/erasure-queue?tab=&cursor=&per_page=
 *   POST /api/v1/admin/compliance/erasure-queue/{user}/hold      (reason required)
 *   POST /api/v1/admin/compliance/erasure-queue/{user}/release   (reason required)
 *   GET  /api/v1/admin/compliance/erasure-queue/{user}           (C3: one row, polled while claimed)
 *   GET  /api/v1/admin/compliance/erasure-queue/{user}/plan      (C3: the dry-run, read-only, rate limited)
 *   POST /api/v1/admin/compliance/erasure-queue/{user}/erase     (C3: the Erase click, flag-gated)
 *
 * `{user}` is the USER ulid: the engine, the ledger, the runbook and `erasure:run` all key on it.
 * Platform admin only, behind the MFA gate (the route stack); cross-agency by design (a platform
 * compliance function), so no tenancy alias, like every admin surface.
 *
 * `erase` is the only write that starts an erasure, and it does not erase in the request: it checks
 * (flag, body, typed ulid, a read-only preflight, the second factor), claims the account and QUEUES
 * the run (`RunErasureJob`, dedicated worker), answering 202. A row carries the person's
 * name and email while the account exists (the admin may see them) and the blocker CODES, never
 * the guards' own sentences (those carry counts and table names). The Erased tab is the ledger.
 */
final class AdminErasureQueueController
{
    public function __construct(
        private readonly ErasureQueue $queue,
        private readonly ErasureHold $hold,
        private readonly ErasePlanner $planner,
        private readonly EraseStarter $starter,
        private readonly SecondFactorStepUp $stepUp,
    ) {}

    /** One queue row, for the page's poll while an account is claimed. */
    public function show(Request $request, string $user): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $row = $this->queue->rowFor($this->subject($user));
        if ($row === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $row]);
    }

    /** The dry-run as numbers and the gate's verdict as codes. Reads only. */
    public function plan(Request $request, string $user): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        try {
            $view = $this->planner->view($this->subject($user));
        } catch (SubjectNotErasable) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'admin.erasure.plan.not_available', 'This account cannot be planned for erasure.');
        }

        return response()->json(['data' => $view]);
    }

    /**
     * The Erase click. Order is the contract (plan §3.5): authorise, flag, body, subject, typed ulid,
     * a read-only preflight, the second factor, the claim, the job after the claim, 202.
     *
     * The typed ulid and the preflight come BEFORE the second factor on purpose: verifying a recovery
     * code burns it, and a click that was always going to be refused must not spend one.
     * Second-factor failures are 403 (never 401: the SPA signs out on 401) and feed the TOTP throttle only;
     * a throttled admin gets 423 with Retry-After, as the creator's deletion door answers.
     */
    public function erase(Request $request, string $user): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        if (! Feature::active(AccountErasureExecuteEnabled::NAME)) {
            return ErrorResponse::single($request, Response::HTTP_CONFLICT, 'admin.erasure.execute.disabled', 'Erasing from the admin is switched off.');
        }

        $data = $request->validate([
            'ulid' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'mfa_code' => ['nullable', 'string', 'max:64'],
        ]);

        /** @var User $admin */
        $admin = $request->user();
        $subject = $this->subject($user);

        if (! hash_equals($subject->ulid, strtoupper(trim($data['ulid'])))) {
            return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'admin.erasure.execute.ulid_mismatch', 'The typed id does not match this account.');
        }

        try {
            $retry = $this->starter->preflight($subject);
        } catch (EraseNotReady $e) {
            return $this->notReady($request, $e);
        } catch (EraseInProgress $e) {
            return ErrorResponse::single($request, Response::HTTP_CONFLICT, 'admin.erasure.execute.in_progress', 'An erasure is already in progress for this account.', meta: ['run' => $e->status->toArray()]);
        }

        $failure = $this->secondFactorFailure($request, $admin, $data['mfa_code'] ?? null);
        if ($failure !== null) {
            return $failure;
        }

        try {
            $started = $this->starter->start($subject, $admin, trim($data['reason']), $data['digest'], $retry);
        } catch (EraseNotReady $e) {
            return $this->notReady($request, $e);
        }

        return response()->json([
            'data' => $this->queue->rowFor($subject->refresh()),
            'meta' => [
                'queued' => $started->queued,
                'retry' => $started->retry,
                'status_url' => '/api/v1/admin/compliance/erasure-queue/'.$subject->ulid,
            ],
        ], Response::HTTP_ACCEPTED);
    }

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

    private function notReady(Request $request, EraseNotReady $e): JsonResponse
    {
        return ErrorResponse::single($request, Response::HTTP_UNPROCESSABLE_ENTITY, 'admin.erasure.execute.not_ready', 'This account is not ready to be erased.', meta: ['codes' => $e->codes]);
    }

    private function secondFactorFailure(Request $request, User $admin, mixed $code): ?JsonResponse
    {
        $outcome = $this->stepUp->verify($admin, is_string($code) ? $code : null, $request, requireEnrolment: true);

        return match ($outcome) {
            SecondFactorOutcome::Passed => null,
            SecondFactorOutcome::Required => ErrorResponse::single($request, Response::HTTP_FORBIDDEN, 'admin.erasure.execute.mfa_required', 'A two-factor code is required.'),
            SecondFactorOutcome::NotEnrolled => ErrorResponse::single($request, Response::HTTP_FORBIDDEN, 'admin.erasure.execute.mfa_not_enrolled', 'Two-factor sign-in is not set up for this admin.'),
            SecondFactorOutcome::Invalid => ErrorResponse::single($request, Response::HTTP_FORBIDDEN, 'admin.erasure.execute.mfa_invalid', 'The two-factor code is not valid.'),
            SecondFactorOutcome::RateLimited => ErrorResponse::single(
                $request,
                Response::HTTP_LOCKED,
                'admin.erasure.execute.mfa_rate_limited',
                'Too many two-factor attempts.',
                headers: ['Retry-After' => (string) $this->stepUp->retryAfterSeconds($admin)],
            ),
            SecondFactorOutcome::EnrollmentSuspended => ErrorResponse::single($request, Response::HTTP_LOCKED, 'admin.erasure.execute.mfa_suspended', 'Two-factor verification is suspended for this admin.'),
        };
    }

    private function subject(string $ulid): User
    {
        /** @var User|null $subject */
        $subject = User::query()->withTrashed()->where('ulid', strtoupper($ulid))->first();
        if ($subject === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $subject;
    }

    private function change(ErasureHoldRequest $request, string $ulid, string $verb): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        /** @var User $admin */
        $admin = $request->user();

        $subject = $this->subject($ulid);

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
