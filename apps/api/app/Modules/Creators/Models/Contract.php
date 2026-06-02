<?php

declare(strict_types=1);

namespace App\Modules\Creators\Models;

use App\Core\Concerns\HasUlid;
use App\Modules\Creators\Database\Factories\ContractFactory;
use App\Modules\Creators\Enums\ContractKind;
use App\Modules\Creators\Enums\ContractStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A contract record (docs/03-DATA-MODEL.md §8).
 *
 * The spec'd, vendor-oriented agreement entity. Polymorphic — attaches to
 * a Creator (master agreement) or a CampaignAssignment (per-campaign
 * addendum) via {@see $subject_type}/{@see $subject_id}.
 *
 * Sprint 4 Chunk 4 builds this to back the flag-OFF click-through accept
 * (D-c4-1/2): the click-through writes a `master_universal` / `signed` /
 * `internal` row keyed to a `creator` subject. The e-sign vendor adapter
 * (a later chunk) reuses the same table, filling the envelope columns
 * (`signature_envelope_id`, `sent_at`, …) — it EXTENDS this model rather
 * than introducing a parallel record.
 *
 * Encryption: `signed_signature_data` holds evidentiary IP/UA/timestamp and
 * is encrypted at rest via the `encrypted:array` cast (mirroring
 * {@see CreatorTaxProfile::$address}; docs/05-SECURITY-COMPLIANCE.md §4). It
 * is NEVER surfaced on a creator-facing resource.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $agency_id
 * @property ContractKind $kind
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $template_id
 * @property int $version
 * @property string $title
 * @property string $body_markdown
 * @property string|null $body_pdf_path
 * @property string|null $signature_provider
 * @property string|null $signature_envelope_id
 * @property ContractStatus $status
 * @property Carbon|null $sent_at
 * @property Carbon|null $signed_at
 * @property int|null $signed_by_creator_id
 * @property array<string, mixed>|null $signed_signature_data
 * @property Carbon|null $expires_at
 * @property int|null $created_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class Contract extends Model
{
    /** @use HasFactory<ContractFactory> */
    use HasFactory;

    use HasUlid;
    use SoftDeletes;

    /**
     * Subject-type discriminator value for a master agreement attached to
     * a Creator. Mirrors the spec's `subject_type` vocabulary (§8, `:580`).
     */
    public const SUBJECT_CREATOR = 'creator';

    /**
     * `signature_provider` value for the flag-OFF click-through acceptance
     * (docs/03-DATA-MODEL.md §8, `:587`). Distinguishes the click-through
     * record from a vendor-signed envelope (`docusign`/`dropboxsign`).
     */
    public const PROVIDER_INTERNAL = 'internal';

    /**
     * `signed_signature_data.method` value for the click-through path.
     */
    public const METHOD_CLICK_THROUGH = 'click_through';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'kind',
        'subject_type',
        'subject_id',
        'template_id',
        'version',
        'title',
        'body_markdown',
        'body_pdf_path',
        'signature_provider',
        'signature_envelope_id',
        'status',
        'sent_at',
        'signed_at',
        'signed_by_creator_id',
        'signed_signature_data',
        'expires_at',
        'created_by_user_id',
    ];

    /**
     * @return BelongsTo<Creator, $this>
     */
    public function signedByCreator(): BelongsTo
    {
        return $this->belongsTo(Creator::class, 'signed_by_creator_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ContractKind::class,
            'status' => ContractStatus::class,
            'version' => 'integer',
            'signed_signature_data' => 'encrypted:array',
            'sent_at' => 'datetime',
            'signed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ContractFactory
    {
        return ContractFactory::new();
    }
}
