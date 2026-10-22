<?php

declare(strict_types=1);

namespace App\Modules\Erasure\Claim;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Erasure\Guards\ErasureRefused;
use App\Modules\Erasure\Guards\Refusals;
use App\Modules\Erasure\Ledger\LedgerMirror;
use App\Modules\Erasure\Models\ErasureLedger;
use App\Modules\Erasure\Subject\SubjectNotErasable;
use App\Modules\Erasure\Subject\SubjectSet;
use App\Modules\Identity\Models\User;
use Illuminate\Database\ConnectionInterface;

/**
 * The gate in front of an erasure, and the only place a claim is made (AH-104c).
 *
 * Why a claim and not a lock inside the erasure's database transaction: the run deletes the
 * S3 objects BEFORE that transaction, and that step cannot be undone. A creator who cancels
 * after the files are gone would be signed in to an account with no files. So the click
 * claims the account first (`users.erasure_started_at`), in one locked transaction that
 * re-reads everything, and from the claim the creator can no longer cancel (the sign-in
 * answers as for an unknown account). A run that fails stays claimed and is retried by an
 * admin; only a refused or moved-data run releases the claim, with its own audit row.
 *
 * `claim()` checks, in one transaction with the user row locked, in this order:
 *   1. the subject is an erasable creator (not erased);
 *   2. it is not held (a hold is released first, in either mode);
 *   3. normal mode: a request stands AND its grace has ended (no early erasure: the 14 days
 *      are a promise); force mode: an operator and a reason, no request needed;
 *   4. no guard refuses (payment, delivered work, vendor);
 * then stamps the claim and writes the audit row. A refusal throws and writes NOTHING.
 *
 * An account that is already claimed is returned its claim again without a new audit row:
 * the gate is asked once, at the first claim. (The engine asks the refusals and the digest
 * again at execute time, always. The hold endpoint refuses a claimed account.)
 */
final class ErasureClaimer
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AuditLogger $audit,
        private readonly LedgerMirror $mirror,
    ) {}

    /**
     * @param  string  $reason  why: mandatory, kept in the audit row after the erasure
     *
     * @throws ErasureRefused a gate failed; nothing was written
     * @throws SubjectNotErasable the account is not a creator, or is already erased
     */
    public function claim(User $user, ?User $operator, ClaimMode $mode, string $reason): ErasureClaim
    {
        if (trim($reason) === '') {
            throw new ErasureRefused(['a reason is required to claim an account for erasure']);
        }
        if ($mode === ClaimMode::ForceNotRequested && $operator === null) {
            throw new ErasureRefused(['forcing an account that never asked needs an operator']);
        }

        return $this->db->transaction(function () use ($user, $operator, $mode, $reason): ErasureClaim {
            /** @var User $locked */
            $locked = User::query()->withTrashed()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $subject = SubjectSet::resolve($locked);

            if ($locked->isErasureClaimed()) {
                return new ErasureClaim($locked->ulid, $mode, $operator?->id);
            }

            $gates = [...$this->requestGates($locked, $mode), ...Refusals::for($subject, $this->db)];
            if ($gates !== []) {
                throw new ErasureRefused($gates);
            }

            $locked->forceFill(['erasure_started_at' => now()])->saveQuietly();

            $this->audit->log(
                action: AuditAction::AccountErasureStarted,
                actor: $operator,
                subject: $locked,
                reason: $reason,
                metadata: ['mode' => $mode->value],
            );

            return new ErasureClaim($locked->ulid, $mode, $operator?->id);
        });
    }

    /**
     * Claim an account whose erasure the LEDGER MIRROR already records, for `erasure:reapply`
     * (a restore brought the person back). The decision to erase this person was made and
     * carried out once; it is not asked again, so there is no request, grace or hold leg. What
     * is asked: the mirror says this ulid was erased, the subject is still erasable, and the
     * guards' refusals (the run asks them again). Idempotent for an account already claimed.
     *
     * @throws ErasureRefused the mirror has no entry for the account, or a guard refuses
     * @throws SubjectNotErasable
     */
    public function reclaim(User $user, ?User $operator): ErasureClaim
    {
        $recorded = false;
        foreach ($this->mirror->entries() as $entry) {
            if (hash_equals($entry->userUlid, $user->ulid)) {
                $recorded = true;

                break;
            }
        }
        if (! $recorded) {
            throw new ErasureRefused(['the ledger mirror has no record that this account was erased']);
        }

        return $this->db->transaction(function () use ($user, $operator): ErasureClaim {
            /** @var User $locked */
            $locked = User::query()->withTrashed()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $subject = SubjectSet::resolve($locked);

            if (! $locked->isErasureClaimed()) {
                $refusals = Refusals::for($subject, $this->db);
                if ($refusals !== []) {
                    throw new ErasureRefused($refusals);
                }

                $locked->forceFill(['erasure_started_at' => now()])->saveQuietly();

                $this->audit->log(
                    action: AuditAction::AccountErasureStarted,
                    actor: $operator,
                    subject: $locked,
                    reason: 'reapply: the ledger mirror records this erasure',
                    metadata: ['mode' => ClaimMode::Reapply->value],
                );
            }

            return new ErasureClaim($locked->ulid, ClaimMode::Reapply, $operator?->id);
        });
    }

    /**
     * The gate's own legs, without the guards' refusals: a hold, and (normal mode) a request
     * whose grace has ended. Read-only and lock-free, so the shell's dry-run can print the
     * verdict; `claim()` asks the same method after it holds the row lock.
     *
     * @return list<string>
     */
    public function requestGates(User $user, ClaimMode $mode): array
    {
        $gates = [];
        if ($user->isErasureHeld()) {
            $gates[] = 'the account is on hold: release the hold first';
        }

        if ($mode === ClaimMode::Normal) {
            if ($user->deletion_requested_at === null || $user->deletion_scheduled_for === null) {
                $gates[] = 'the account has not asked to be deleted';
            } elseif ($user->deletion_scheduled_for->isFuture()) {
                $gates[] = 'the grace has not ended: the account is not due until '.$user->deletion_scheduled_for->toDateString();
            }
        }

        return $gates;
    }

    /**
     * The claim of an account that is already claimed, for the queued job and for a retry.
     * It makes no gate decision: it only refuses an account that is not claimed.
     *
     * @throws ErasureRefused when the account is not claimed
     */
    public function resume(User $user): ErasureClaim
    {
        $fresh = User::query()->withTrashed()->whereKey($user->getKey())->first();
        if ($fresh === null || ! $fresh->isErasureClaimed()) {
            throw new ErasureRefused(['the account is not claimed for erasure']);
        }

        return new ErasureClaim($fresh->ulid, ClaimMode::Normal, null);
    }

    /**
     * Hand the account back: the run was refused, or its data moved since the admin looked,
     * and nothing irreversible happened. The creator may cancel again. Returns false when
     * there was nothing to release (not claimed, or already erased) AND when a run has
     * already begun for the account (a ledger row exists: its files may be gone, so the
     * claim stays and the run is retried, never reopened to the creator).
     *
     * @param  string  $cause  a short code, never a sentence that could carry a count or a name
     */
    public function release(User $user, ?User $operator, string $cause): bool
    {
        return $this->db->transaction(function () use ($user, $operator, $cause): bool {
            /** @var User $locked */
            $locked = User::query()->withTrashed()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isErasureClaimed()) {
                return false;
            }

            // A ledger row is written before the first irreversible step, so its existence is
            // the proof that something may already be gone.
            if (ErasureLedger::query()->where('user_ulid', $locked->ulid)->exists()) {
                return false;
            }

            $locked->forceFill(['erasure_started_at' => null])->saveQuietly();

            $this->audit->log(
                action: AuditAction::AccountErasureClaimReleased,
                actor: $operator,
                subject: $locked,
                metadata: ['cause' => $cause],
            );

            return true;
        });
    }
}
