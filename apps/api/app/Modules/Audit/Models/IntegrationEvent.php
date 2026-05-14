<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Inbound webhook event log row. Distinct from {@see AuditLog}
 * (Refinement 5 in the chunk-2 plan):
 *
 *   - {@see AuditLog} is the platform-action history — append-only,
 *     partitioned for retention, written by the {@see Audited}
 *     trait or by service-layer code that calls
 *     {@see AuditLogger}.
 *
 *   - IntegrationEvent is the vendor-payload history — append-on-
 *     receipt + update-once-on-process, never deleted, written by
 *     the webhook controller (insert) and by Process*WebhookJob
 *     (update). It powers idempotency (the unique index on
 *     {@see App\Modules\Creators\Http\Controllers\Webhooks} is the
 *     dedupe primitive) and post-hoc debugging / replay.
 *
 * IntegrationEvent does NOT extend the {@see \App\Modules\Audit\
 * Concerns\Audited} trait and does NOT auto-emit audit rows on
 * insert. The webhook controller emits an explicit
 * {@see AuditAction::IntegrationWebhookReceived}
 * row alongside the IntegrationEvent insert; the two writes are
 * independent and serve different audiences (audit_logs for
 * compliance / operator review; integration_events for vendor-
 * payload archaeology). Pinned by a source-inspection regression
 * test (#1 standing standard) that asserts no `Audited` use here.
 *
 * Lives under app/Modules/Audit/Models/ to centralise cross-cutting
 * logging concerns (Q-module-location decision in the chunk-2
 * plan); the Creators module imports the FQCN from here for the
 * webhook controllers + Process*WebhookJob.
 *
 * @property int $id
 * @property string $provider
 * @property string $provider_event_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 * @property string|null $processing_error
 * @property Carbon $received_at
 */
final class IntegrationEvent extends Model
{
    /**
     * Append-on-receipt + update-once-on-process: only `received_at`
     * is set on insert; `processed_at` and `processing_error` are
     * the only mutable fields, both set by Process*WebhookJob.
     * The standard Laravel `created_at` / `updated_at` columns are
     * intentionally NOT present (the data-model spec deliberately
     * omits them — `received_at` is the canonical timestamp).
     */
    public $timestamps = false;

    protected $table = 'integration_events';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'provider_event_id',
        'event_type',
        'payload',
        'received_at',
        'processed_at',
        'processing_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
