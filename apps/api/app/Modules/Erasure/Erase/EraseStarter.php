<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Erase;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Erasure\Claim\ClaimMode;
use App\Modules\Erasure\Claim\ErasureClaimer;
use App\Modules\Erasure\Eligibility\ErasureEligibility;
use App\Modules\Erasure\Guards\ErasureRefused;
use App\Modules\Erasure\Jobs\RunErasureJob;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Erasure\Queue\ErasureRunStatus;
use App\Modules\Erasure\Subject\SubjectNotErasable;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;

/**
 * The Erase click, after the controller has checked the flag, the typed ulid and the second
 * factor (AH-104c C3): the read-only {@see preflight()}, then {@see start()} = claim, then queue.
 *
 * Why two steps. The controller asks `preflight()` BEFORE it verifies the admin's second-factor
 * code, so a click that was always going to be refused (held, not due, blocked, already running)
 * never spends a recovery code (verifying one burns it). `start()` asks the gate again under the
 * row lock, as it must: the answer can change between the two.
 *
 * Order inside `start()` is the contract: the claim first (one locked transaction, committed),
 * the job queued only AFTER it, only by the call that made the claim (or a retry of an
 * incomplete / stale run). A refused claim queues nothing. A second click on a claimed account
 * whose run is queued or running queues nothing.
 */
final class EraseStarter
{
    public function __construct(
        private readonly ErasureClaimer $claimer,
        private readonly ErasureEligibility $eligibility,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Read-only. Returns whether this click is a RETRY of a claimed account (true) or a first claim (false).
     *
     * @throws EraseNotReady
     * @throws EraseInProgress
     */
    public function preflight(User $user): bool
    {
        if ($user->type !== UserType::Creator) {
            throw new EraseNotReady([EraseNotReady::NOT_AVAILABLE]);
        }
        if ($user->isErased()) {
            throw new EraseNotReady([EraseNotReady::ERASED]);
        }

        if ($user->isErasureClaimed()) {
            $status = ErasureRunStatus::for($user, $this->latestLedger($user));
            if ($status === null || ! $status->canRetry) {
                throw new EraseInProgress($status ?? new ErasureRunStatus(ErasureRunStatus::WAITING, false, false));
            }

            return true;
        }

        $codes = $this->notReadyCodes($user);
        if ($codes !== []) {
            throw new EraseNotReady($codes);
        }

        return false;
    }

    /**
     * @throws EraseNotReady the gate refused under the lock; nothing was claimed or queued
     */
    public function start(User $user, User $operator, string $reason, string $digest, bool $retry): EraseStarted
    {
        try {
            $claim = $this->claimer->claim($user, $operator, ClaimMode::Normal, $reason);
        } catch (ErasureRefused $e) {
            $codes = $this->notReadyCodes($user->refresh());

            throw new EraseNotReady($codes === [] ? [EraseNotReady::NOT_AVAILABLE] : $codes);
        } catch (SubjectNotErasable) {
            throw new EraseNotReady([EraseNotReady::NOT_AVAILABLE]);
        }

        // Only the call that made the claim queues the run; a retry is the one other case.
        if (! $claim->created && ! $retry) {
            return new EraseStarted($claim, retry: false, queued: false);
        }

        if ($retry) {
            $this->audit->log(
                action: AuditAction::AccountErasureStarted,
                actor: $operator,
                subject: $user,
                reason: $reason,
                metadata: ['mode' => 'retry'],
            );
        }

        RunErasureJob::dispatch($user->ulid, $operator->id, $digest)->afterCommit();

        return new EraseStarted($claim, retry: $retry, queued: true);
    }

    /** @return list<string> */
    private function notReadyCodes(User $user): array
    {
        try {
            return [...$this->claimer->gateCodes($user, ClaimMode::Normal), ...$this->eligibility->blockers($user)];
        } catch (SubjectNotErasable) {
            return [EraseNotReady::NOT_AVAILABLE];
        }
    }

    private function latestLedger(User $user): ?ErasureLedger
    {
        return ErasureLedger::query()->where('user_ulid', $user->ulid)->orderByDesc('id')->first();
    }
}
