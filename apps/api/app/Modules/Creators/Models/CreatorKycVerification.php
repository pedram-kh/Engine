<?php

declare(strict_types=1);

namespace App\Modules\Creators\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Creators\Database\Factories\CreatorKycVerificationFactory;
use App\Modules\Creators\Enums\KycVerificationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-attempt KYC verification record.
 *
 * Encryption (#23 of data-model spec):
 *   - decision_data (full provider response, encrypted JSON blob)
 *
 * @property int $id
 * @property string $ulid
 * @property int $creator_id
 * @property string $provider
 * @property string|null $provider_session_id
 * @property string|null $provider_decision_id
 * @property KycVerificationStatus $status
 * @property array<string, mixed>|null $decision_data
 * @property string|null $failure_reason
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class CreatorKycVerification extends Model
{
    /** @use HasFactory<CreatorKycVerificationFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'creator_id',
        'provider',
        'provider_session_id',
        'provider_decision_id',
        'status',
        'decision_data',
        'failure_reason',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    /**
     * @return BelongsTo<Creator, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => KycVerificationStatus::class,
            'decision_data' => 'encrypted:array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CreatorKycVerificationFactory
    {
        return CreatorKycVerificationFactory::new();
    }
}
