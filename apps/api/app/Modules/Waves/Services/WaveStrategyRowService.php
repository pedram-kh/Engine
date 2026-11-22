<?php

declare(strict_types=1);

namespace App\Modules\Waves\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Enums\WaveStepSource;
use App\Modules\Waves\Enums\WaveStepStatus;
use App\Modules\Waves\Models\Wave;
use App\Modules\Waves\Models\WaveStep;
use App\Modules\Waves\Steps\WaveStrategyRowRules;
use App\Modules\Waves\Steps\WaveTrackerCalculator;
use Illuminate\Support\Carbon;

/**
 * The ONLY writer of a strategy tracker row on behalf of the system (PL-2.5). It applies {@see WaveStrategyRowRules} to the
 * rows whose real step is given, inside the caller's transaction and under the wave lock the caller already holds, and
 * audits each row it changes as `wave_step.updated` with `reason` `system` or `system_took_back`.
 */
final class WaveStrategyRowService
{
    public function __construct(private readonly WaveStepProvisioner $provisioner) {}

    /**
     * @param  array<string, array{before: string|null, after: string}>  $transitions  tracker step key => real state before and after
     */
    public function apply(Wave $locked, array $transitions, ?User $actor, bool $catchUp): void
    {
        if ($transitions === []) {
            return;
        }

        // The default rows are written once (marker-guarded). A wave whose tracker was never opened gets them here, so the
        // Brand page bar follows its first strategy change.
        $this->provisioner->ensure($locked);

        $rows = WaveStep::query()
            ->where('wave_id', $locked->id)
            ->whereIn('step_key', array_keys($transitions))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            $t = $transitions[(string) $row->step_key];
            $decision = WaveStrategyRowRules::decide($row->status, $row->status_source, $t['before'], $t['after'], $catchUp);
            if ($decision === null) {
                continue;
            }

            $before = $this->snapshot($row);
            $row->status = WaveStepStatus::from($decision['status']);
            $row->status_source = WaveStepSource::from($decision['source']);
            $row->completed_on = $row->status === WaveStepStatus::Done
                ? ($before['status'] === 'done' ? $row->completed_on : Carbon::parse(WaveTrackerCalculator::todayFor($actor?->timezone)))
                : null;
            if (! $row->isDirty()) {
                continue;
            }
            $row->save();
            $after = $this->snapshot($row);

            Audit::log(
                action: AuditAction::WaveStepUpdated,
                actor: $actor,
                subject: $row,
                // Literal keys on purpose (AuditPayloadKeysGuardTest reads them statically).
                before: [
                    'stream' => $before['stream'],
                    'step_key' => $before['step_key'],
                    'name' => $before['name'],
                    'status' => $before['status'],
                    'owner_client' => $before['owner_client'],
                    'status_source' => $before['status_source'],
                    'starts_on' => $before['starts_on'],
                    'due_on' => $before['due_on'],
                    'completed_on' => $before['completed_on'],
                ],
                after: [
                    'stream' => $after['stream'],
                    'step_key' => $after['step_key'],
                    'name' => $after['name'],
                    'status' => $after['status'],
                    'owner_client' => $after['owner_client'],
                    'status_source' => $after['status_source'],
                    'starts_on' => $after['starts_on'],
                    'due_on' => $after['due_on'],
                    'completed_on' => $after['completed_on'],
                ],
                metadata: ['reason' => $decision['reason']],
                agencyId: $row->agency_id,
            );
        }
    }

    /**
     * @return array{stream: string, step_key: string|null, name: string, status: string, owner_client: bool, status_source: string, starts_on: string|null, due_on: string|null, completed_on: string|null}
     */
    private function snapshot(WaveStep $step): array
    {
        return [
            'stream' => $step->stream->value,
            'step_key' => $step->step_key,
            'name' => $step->name,
            'status' => $step->status->value,
            'owner_client' => $step->owner_client,
            'status_source' => $step->status_source->value,
            'starts_on' => $step->starts_on?->toDateString(),
            'due_on' => $step->due_on?->toDateString(),
            'completed_on' => $step->completed_on?->toDateString(),
        ];
    }
}
