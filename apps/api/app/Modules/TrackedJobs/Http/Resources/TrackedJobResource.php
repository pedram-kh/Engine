<?php

declare(strict_types=1);

namespace App\Modules\TrackedJobs\Http\Resources;

use App\Modules\TrackedJobs\Models\TrackedJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Spec § 18 response shape:
 *
 *   {
 *     "data": {
 *       "id": "01HQ...",
 *       "type": "data_export",
 *       "status": "processing",
 *       "progress": 0.65,
 *       "started_at": "...",
 *       "estimated_completion_at": "...",
 *       "result": null
 *     }
 *   }
 *
 * The `type` field maps to TrackedJob::kind. estimated_completion_at
 * may legitimately be null for jobs that don't track an ETA (D-pause-8).
 *
 * @mixin TrackedJob
 */
final class TrackedJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $job = $this->resource;
        assert($job instanceof TrackedJob);

        return [
            'id' => $job->ulid,
            'type' => $job->kind,
            'status' => $job->status->value,
            'progress' => $job->progress,
            'started_at' => $job->started_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'estimated_completion_at' => $job->estimated_completion_at?->toIso8601String(),
            'result' => $job->result,
            'failure_reason' => $job->failure_reason,
        ];
    }
}
