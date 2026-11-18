<?php

declare(strict_types=1);

namespace App\Modules\Waves\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Waves\Enums\WaveStepSource;
use App\Modules\Waves\Enums\WaveStepStatus;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveStep;
use App\Modules\Waves\Steps\WaveStepDefaults;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives a wave its ready-made step list, once (PL-1.3 plan section 3.1, ruling T1). Used at wave creation (inside the
 * creation transaction) and on the first open of the tracker of a wave created before this build.
 *
 * `waves.steps_provisioned_at` is what makes it ONCE: if a strategist later deletes every step, or a whole stream,
 * no later open writes anything back. The marker never records which streams exist. The unique
 * `(wave_id, step_key)` index is the belt: if two first opens both pass the marker check (SQLite ignores row locks),
 * the loser's insert is refused, rolled back, and it returns having written nothing. It is the
 * `BoardService::ensureBoard` precedent: a write on a GET, idempotent.
 */
final class WaveStepProvisioner
{
    /**
     * @return bool true when this call wrote the default steps
     */
    public function ensure(Wave $wave): bool
    {
        try {
            return DB::transaction(function () use ($wave): bool {
                $locked = Wave::query()->whereKey($wave->id)->lockForUpdate()->firstOrFail();

                if ($locked->steps_provisioned_at !== null) {
                    return false;
                }

                $now = now();
                $rows = [];
                foreach (WaveStepDefaults::steps() as $step) {
                    $rows[] = [
                        'ulid' => (string) Str::ulid(),
                        'agency_id' => $locked->agency_id,
                        'wave_id' => $locked->id,
                        'stream' => $step['stream']->value,
                        'step_key' => $step['key'],
                        'name' => $step['name'],
                        'position' => $step['position'],
                        'status' => WaveStepStatus::NotStarted->value,
                        'owner_user_id' => null,
                        'owner_client' => $step['owner_client'],
                        'status_source' => WaveStepSource::System->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                WaveStep::query()->insert($rows);

                $locked->forceFill(['steps_provisioned_at' => $now])->saveQuietly();
                $wave->steps_provisioned_at = $locked->steps_provisioned_at;

                Audit::log(
                    action: AuditAction::WaveStepsProvisioned,
                    subject: $locked,
                    // Literal keys on purpose (AuditPayloadKeysGuardTest reads them statically).
                    metadata: ['row_count' => count($rows)],
                    agencyId: $locked->agency_id,
                );

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // The loser of a first-open race: the winner already wrote the steps and the marker.
            return false;
        }
    }
}
