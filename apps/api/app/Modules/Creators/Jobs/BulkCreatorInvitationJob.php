<?php

declare(strict_types=1);

namespace App\Modules\Creators\Jobs;

use App\Modules\Agencies\Models\Agency;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Creators\Services\BulkInviteService;
use App\Modules\Identity\Models\User;
use App\Modules\TrackedJobs\Enums\TrackedJobStatus;
use App\Modules\TrackedJobs\Models\TrackedJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Processes a bulk creator-invitation batch in the background.
 *
 * The job:
 *   1. Updates the {@see TrackedJob} status to `processing`.
 *   2. For each row: invokes {@see BulkInviteService::inviteOne()}.
 *   3. Aggregates per-row outcomes into the TrackedJob.result column.
 *   4. Marks the TrackedJob `complete` (or `failed` if the whole job
 *      throws) and emits the matching audit row.
 *
 * Per-row failures are NOT retries — the row is recorded as failed in
 * the result aggregate and the job continues. This keeps the operator
 * experience predictable: one bad row never stalls the rest of the
 * batch.
 */
final class BulkCreatorInvitationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $emails  pre-validated, normalised emails
     */
    public function __construct(
        public readonly int $trackedJobId,
        public readonly int $agencyId,
        public readonly int $inviterUserId,
        public readonly array $emails,
    ) {}

    public function handle(BulkInviteService $service): void
    {
        $tracked = TrackedJob::query()->findOrFail($this->trackedJobId);
        $agency = Agency::query()->findOrFail($this->agencyId);
        $inviter = User::query()->findOrFail($this->inviterUserId);

        $tracked->forceFill([
            'status' => TrackedJobStatus::Processing,
            'started_at' => now(),
        ])->save();

        Audit::log(
            action: AuditAction::BulkInviteStarted,
            actor: $inviter,
            subject: $tracked,
            agencyId: $agency->id,
            metadata: ['row_count' => count($this->emails)],
        );

        $stats = ['invited' => 0, 'already_invited' => 0, 'failed' => 0];
        $failures = [];

        try {
            foreach ($this->emails as $i => $email) {
                $result = $service->inviteOne($agency, $inviter, $email);
                $stats[$result['outcome']]++;

                if ($result['outcome'] === 'failed') {
                    $failures[] = [
                        'email' => $email,
                        'reason' => $result['reason'] ?? 'Unknown failure.',
                    ];
                }

                if (($i + 1) % 25 === 0 || $i + 1 === count($this->emails)) {
                    $tracked->forceFill([
                        'progress' => round(($i + 1) / max(count($this->emails), 1), 4),
                    ])->save();
                }
            }

            $tracked->forceFill([
                'status' => TrackedJobStatus::Complete,
                'progress' => 1.0,
                'completed_at' => now(),
                'result' => ['stats' => $stats, 'failures' => $failures],
            ])->save();

            Audit::log(
                action: AuditAction::BulkInviteCompleted,
                actor: $inviter,
                subject: $tracked,
                agencyId: $agency->id,
                metadata: $stats,
            );
        } catch (Throwable $e) {
            $tracked->forceFill([
                'status' => TrackedJobStatus::Failed,
                'completed_at' => now(),
                'failure_reason' => $e->getMessage(),
                'result' => ['stats' => $stats, 'failures' => $failures],
            ])->save();

            Audit::log(
                action: AuditAction::BulkInviteFailed,
                actor: $inviter,
                subject: $tracked,
                agencyId: $agency->id,
                metadata: ['error' => $e->getMessage()],
            );

            throw $e;
        }
    }
}
