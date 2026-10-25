<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Hold;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Database\ConnectionInterface;

/**
 * An admin's legal hold on an account (AH-104c C2): while it stands the erasure gate refuses the
 * account in every mode, including the shell's `--force-not-requested`.
 *
 * What a hold is, and is not:
 *   - It needs no request: a person who never asked can be held (a legal obligation does not wait
 *     for the person). Only a creator account can be held.
 *   - The creator is not told and nothing visible changes. In particular a held creator can still
 *     cancel a deletion request by signing in, and **the hold stays** (ruling Q4): cancelling
 *     touches the two request columns only, and releasing touches the two hold columns only.
 *     A later re-request shows as held again.
 *   - A claimed account cannot be held (the run has taken it; a hold would be a promise nobody can
 *     keep) and an erased account cannot be held or released.
 *   - Both verbs need a reason, written to the audit row with the admin as actor. The reason is
 *     the only place the hold's why is kept (the columns hold timestamps and an admin id): it
 *     must carry no personal details.
 *
 * Locked and idempotent: holding a held account, or releasing one that is not held, returns false
 * and writes nothing, not even an audit row.
 */
final class ErasureHold
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return bool true when this call placed the hold; false when it was already held
     *
     * @throws ErasureHoldRefused
     */
    public function hold(User $subject, User $admin, string $reason): bool
    {
        $reason = $this->reason($reason);

        return $this->db->transaction(function () use ($subject, $admin, $reason): bool {
            $locked = $this->lock($subject);

            if ($locked->isErasureClaimed()) {
                throw ErasureHoldRefused::claimed();
            }
            if ($locked->isErasureHeld()) {
                return false;
            }

            $locked->forceFill(['erasure_held_at' => now(), 'erasure_held_by_user_id' => $admin->id])->saveQuietly();

            $this->audit->log(
                action: AuditAction::AccountErasureHeld,
                actor: $admin,
                subject: $locked,
                reason: $reason,
            );

            return true;
        });
    }

    /**
     * @return bool true when this call released a hold; false when there was none
     *
     * @throws ErasureHoldRefused
     */
    public function release(User $subject, User $admin, string $reason): bool
    {
        $reason = $this->reason($reason);

        return $this->db->transaction(function () use ($subject, $admin, $reason): bool {
            $locked = $this->lock($subject);

            if (! $locked->isErasureHeld()) {
                return false;
            }

            $locked->forceFill(['erasure_held_at' => null, 'erasure_held_by_user_id' => null])->saveQuietly();

            $this->audit->log(
                action: AuditAction::AccountErasureHoldReleased,
                actor: $admin,
                subject: $locked,
                reason: $reason,
            );

            return true;
        });
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ErasureHoldRefused::reasonRequired();
        }

        return $reason;
    }

    /** @throws ErasureHoldRefused */
    private function lock(User $subject): User
    {
        /** @var User $locked */
        $locked = User::query()->withTrashed()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->type !== UserType::Creator) {
            throw ErasureHoldRefused::notACreator();
        }
        if ($locked->isErased()) {
            throw ErasureHoldRefused::erased();
        }

        return $locked;
    }
}
