<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Erasure\Eligibility\ErasureEligibility;
use App\Modules\Erasure\Subject\SubjectNotErasable;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Events\AccountDeletionCancelled;
use App\Modules\Identity\Events\AccountDeletionRequested;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use SensitiveParameter;

/**
 * A creator asks for their own account to be deleted (AH-104b-del A3).
 *
 * This service only SCHEDULES: it stamps the two dates, takes away every way
 * of signing in, and records that it did. Nothing is erased here; the
 * 14-day grace ends and the erasure engine (AH-104a) does the rest.
 *
 * Order matters and is part of the contract:
 *   1. Creators only, not erased, not suspended (Q11: a suspended account is
 *      held for a reason and does not get to leave it by this door).
 *   2. The password. A wrong one changes NOTHING: no counter, no lock, no
 *      token touched (Q6: a door that signs the owner out on bad input
 *      would be a tool for locking people out).
 *   3. The second factor, when the account has one: the same throttle and
 *      suspension rules as sign-in, but a failure here never feeds the
 *      account-lockout counters.
 *   4. Eligibility (payments, vendors), so a creator is told up front and
 *      not after the grace.
 *   5. One transaction: re-read the row locked (a concurrent second request
 *      answers with the first one's date), stamp, revoke, audit.
 *   6. After the commit: the event the confirmation mail hangs on.
 */
final class AccountDeletionService
{
    /** Days between the request and the erasure (Q10: stored, never recomputed). */
    public const GRACE_DAYS = 14;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Hasher $hasher,
        private readonly SecondFactorStepUp $stepUp,
        private readonly ErasureEligibility $eligibility,
        private readonly CredentialRevoker $revoker,
        private readonly AuditLogger $audit,
        private readonly Dispatcher $events,
    ) {}

    public function request(
        User $user,
        #[SensitiveParameter] string $password,
        #[SensitiveParameter] ?string $mfaCode,
        Request $request,
    ): AccountDeletionResult {
        if ($user->type !== UserType::Creator || $user->isErased() || $user->isErasureClaimed() || $user->isSuspended()) {
            return AccountDeletionResult::failed(AccountDeletionStatus::NotAvailable);
        }

        if (! $this->hasher->check($password, $user->password)) {
            return AccountDeletionResult::failed(AccountDeletionStatus::InvalidPassword);
        }

        $ip = $request->ip();
        $userAgent = $request->userAgent();

        $failure = $this->checkSecondFactor($user, $mfaCode, $request);
        if ($failure !== null) {
            return $failure;
        }

        try {
            $blockers = $this->eligibility->blockers($user);
        } catch (SubjectNotErasable) {
            return AccountDeletionResult::failed(AccountDeletionStatus::NotAvailable);
        }
        if ($blockers !== []) {
            return AccountDeletionResult::blocked($blockers);
        }

        $result = $this->db->transaction(function () use ($user, $ip, $userAgent): AccountDeletionResult {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $openRequestedAt = $locked->deletion_requested_at;
            $openScheduledFor = $locked->deletion_scheduled_for;
            if ($locked->isDeletionPending() && $openRequestedAt !== null && $openScheduledFor !== null) {
                return AccountDeletionResult::alreadyPending($openRequestedAt, $openScheduledFor);
            }

            $requestedAt = CarbonImmutable::now();
            $scheduledFor = $requestedAt->addDays(self::GRACE_DAYS);

            $locked->forceFill([
                'deletion_requested_at' => $requestedAt,
                'deletion_scheduled_for' => $scheduledFor,
            ])->saveQuietly();

            $revoked = $this->revoker->revokeAll($locked);

            $this->audit->log(
                action: AuditAction::AccountDeletionRequested,
                actor: $locked,
                subject: $locked,
                metadata: [
                    'scheduled_for' => $scheduledFor->toIso8601String(),
                    'revoked' => $revoked,
                ],
                ip: $ip,
                userAgent: $userAgent,
            );

            return AccountDeletionResult::requested($requestedAt, $scheduledFor);
        });

        if ($result->status === AccountDeletionStatus::Requested && $result->scheduledFor !== null) {
            $user->refresh();
            $this->events->dispatch(new AccountDeletionRequested($user, $result->scheduledFor));
        }

        return $result;
    }

    /**
     * The creator signed in with `cancel_deletion` and passed every gate (A4):
     * clear the two dates and say so. Returns false only when the account was
     * erased, or claimed for erasure (AH-104c), in the meantime (the row is re-read
     * locked), in which case nothing is restored; the caller answers it as an
     * unknown account.
     */
    public function cancel(User $user, ?string $ip, ?string $userAgent): bool
    {
        $cancelledNow = false;

        $restored = $this->db->transaction(function () use ($user, $ip, $userAgent, &$cancelledNow): bool {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            // Erased, or claimed for erasure (AH-104c): the claim ends the creator's right to
            // cancel. The row is re-read locked, and the claimer locks the same row, so a
            // cancel and a claim cannot both win.
            if ($locked->isErased() || $locked->isErasureClaimed()) {
                return false;
            }

            $scheduledFor = $locked->deletion_scheduled_for;
            if (! $locked->isDeletionPending()) {
                return true; // already cancelled by a concurrent sign-in: nothing to do
            }

            $locked->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null])->saveQuietly();

            $this->audit->log(
                action: AuditAction::AccountDeletionCancelled,
                actor: $locked,
                subject: $locked,
                metadata: ['was_scheduled_for' => $scheduledFor?->toIso8601String()],
                ip: $ip,
                userAgent: $userAgent,
            );

            $cancelledNow = true;

            return true;
        });

        $user->refresh();

        // After the commit, and only for the call that actually cancelled: the Campaigns module
        // re-runs the post checks it held back while the request stood (AH-104c C1-5).
        if ($cancelledNow) {
            $this->events->dispatch(new AccountDeletionCancelled($user));
        }

        return $restored;
    }

    private function checkSecondFactor(
        User $user,
        #[SensitiveParameter] ?string $mfaCode,
        Request $request,
    ): ?AccountDeletionResult {
        return match ($this->stepUp->verify($user, $mfaCode, $request)) {
            SecondFactorOutcome::Passed => null,
            SecondFactorOutcome::EnrollmentSuspended => AccountDeletionResult::failed(AccountDeletionStatus::MfaEnrollmentSuspended),
            SecondFactorOutcome::Required, SecondFactorOutcome::NotEnrolled => AccountDeletionResult::failed(AccountDeletionStatus::MfaRequired),
            SecondFactorOutcome::RateLimited => AccountDeletionResult::failed(
                AccountDeletionStatus::MfaRateLimited,
                $this->stepUp->retryAfterSeconds($user),
            ),
            SecondFactorOutcome::Invalid => AccountDeletionResult::failed(AccountDeletionStatus::MfaInvalidCode),
        };
    }
}
