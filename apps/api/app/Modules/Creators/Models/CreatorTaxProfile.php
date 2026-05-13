<?php

declare(strict_types=1);

namespace App\Modules\Creators\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Audit\Concerns\Audited;
use App\Modules\Audit\Contracts\Auditable;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Creators\Database\Factories\CreatorTaxProfileFactory;
use App\Modules\Creators\Enums\TaxFormType;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tax profile for a Creator. One row per creator.
 *
 * Encryption (#23 of data-model spec):
 *   - legal_name
 *   - tax_id
 *   - address (JSON struct stored as encrypted text blob)
 *
 * Audited per spec §20. The auditableAllowlist intentionally excludes
 * the encrypted PII fields (legal_name, tax_id, address) — audit
 * snapshots would defeat the encryption-at-rest discipline.
 *
 * @property int $id
 * @property string $ulid
 * @property int $creator_id
 * @property string $legal_name
 * @property TaxFormType $tax_form_type
 * @property string $tax_id
 * @property string $tax_id_country
 * @property array<string, mixed> $address
 * @property Carbon|null $submitted_at
 * @property Carbon|null $verified_at
 * @property int|null $verified_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class CreatorTaxProfile extends Model implements Auditable
{
    use Audited;

    /** @use HasFactory<CreatorTaxProfileFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'creator_id',
        'legal_name',
        'tax_form_type',
        'tax_id',
        'tax_id_country',
        'address',
        'submitted_at',
        'verified_at',
        'verified_by_user_id',
    ];

    /**
     * @return BelongsTo<Creator, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * Spec §20 names CreatorTaxProfile as Audited. The allowlist
     * EXCLUDES the encrypted PII fields by design — defense-in-depth
     * against accidental audit-log leakage of legal_name/tax_id/address.
     * Only state-change fields are audited.
     *
     * @return list<string>
     */
    public function auditableAllowlist(): array
    {
        return [
            'tax_form_type',
            'tax_id_country',
            'submitted_at',
            'verified_at',
            'verified_by_user_id',
        ];
    }

    public function auditAction(string $event): AuditAction
    {
        return match ($event) {
            'created' => AuditAction::CreatorTaxProfileCreated,
            'updated' => AuditAction::CreatorTaxProfileUpdated,
            'deleted' => AuditAction::CreatorTaxProfileDeleted,
            default => AuditAction::CreatorTaxProfileUpdated,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_form_type' => TaxFormType::class,
            'legal_name' => 'encrypted',
            'tax_id' => 'encrypted',
            'address' => 'encrypted:array',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CreatorTaxProfileFactory
    {
        return CreatorTaxProfileFactory::new();
    }
}
