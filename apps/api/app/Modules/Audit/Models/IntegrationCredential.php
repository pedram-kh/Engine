<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Tests\Feature\Modules\Audit\IntegrationCredentialTest;

/**
 * Per-provider integration credential row.
 *
 * Sprint 3 Chunk 2 ships this model + table but does NOT write to
 * it — mock provider credentials live in `config/integrations.php`
 * and real-vendor secrets live in AWS Secrets Manager (per
 * docs/06-INTEGRATIONS.md § 1.2). Future sprints (Sprint 4 KYC,
 * Sprint 7 Stripe, Sprint 9 e-sign) write here when an agency
 * needs to override the global vendor account (e.g., a custom
 * DocuSign tenant).
 *
 * The `credentials` column is encrypted at the application layer
 * via the `encrypted:array` cast per docs/03-DATA-MODEL.md § 23.
 * The cast non-negotiable: a future engineer who removes it (or
 * widens to plaintext) re-opens a P0 secret-leak surface — pinned
 * by a #1 source-inspection regression test in
 * {@see IntegrationCredentialTest}.
 *
 * Sits in app/Modules/Audit/Models/ alongside
 * {@see IntegrationEvent} (Q-module-location decision in the
 * chunk-2 plan) — both are cross-cutting integration logs.
 *
 * @property int $id
 * @property int|null $agency_id
 * @property string $provider
 * @property array<string, mixed> $credentials
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class IntegrationCredential extends Model
{
    protected $table = 'integration_credentials';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'provider',
        'credentials',
        'expires_at',
    ];

    /**
     * The `encrypted:array` cast on `credentials` is the
     * application-layer half of the encryption contract per
     * docs/03-DATA-MODEL.md § 23. NEVER widen to a plain `array`
     * cast — pinned by a source-inspection regression test (#1).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'expires_at' => 'datetime',
        ];
    }
}
