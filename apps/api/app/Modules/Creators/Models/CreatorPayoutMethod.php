<?php

declare(strict_types=1);

namespace App\Modules\Creators\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Audit\Concerns\Audited;
use App\Modules\Audit\Contracts\Auditable;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Creators\Database\Factories\CreatorPayoutMethodFactory;
use App\Modules\Creators\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Payout method for a Creator. P1 supports Stripe Connect Express only.
 *
 * Audited per spec §20. provider_account_id is included in the audit
 * allowlist because state changes on the Stripe Connect account
 * (verification, restriction, disablement) need a forensic trail.
 *
 * @property int $id
 * @property string $ulid
 * @property int $creator_id
 * @property string $provider
 * @property string $provider_account_id
 * @property string $currency
 * @property bool $is_default
 * @property PayoutStatus $status
 * @property Carbon|null $verified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class CreatorPayoutMethod extends Model implements Auditable
{
    use Audited;

    /** @use HasFactory<CreatorPayoutMethodFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'creator_id',
        'provider',
        'provider_account_id',
        'currency',
        'is_default',
        'status',
        'verified_at',
    ];

    /**
     * @return BelongsTo<Creator, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /**
     * @return list<string>
     */
    public function auditableAllowlist(): array
    {
        return [
            'provider',
            'provider_account_id',
            'currency',
            'is_default',
            'status',
            'verified_at',
        ];
    }

    public function auditAction(string $event): AuditAction
    {
        return match ($event) {
            'created' => AuditAction::CreatorPayoutMethodCreated,
            'updated' => AuditAction::CreatorPayoutMethodUpdated,
            'deleted' => AuditAction::CreatorPayoutMethodDeleted,
            default => AuditAction::CreatorPayoutMethodUpdated,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'is_default' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CreatorPayoutMethodFactory
    {
        return CreatorPayoutMethodFactory::new();
    }
}
