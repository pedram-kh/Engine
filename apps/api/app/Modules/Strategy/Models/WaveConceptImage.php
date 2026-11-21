<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A reference image of a concept (PL-2.3). `pending` is a reservation made at upload init (it counts against the limits
 * until `expires_at`); `ready` carries the stored object's own MIME and size. `storage_key` is never exposed.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property int $market_id
 * @property int $concept_id
 * @property string $storage_key
 * @property string|null $thumb_key
 * @property string $original_filename
 * @property string|null $mime_type
 * @property int $declared_bytes
 * @property int|null $size_bytes
 * @property string $status
 * @property Carbon|null $expires_at
 * @property int $position
 * @property int|null $uploaded_by_user_id
 * @property-read WaveConcept $concept
 */
final class WaveConceptImage extends Model
{
    use BelongsToAgency;
    use HasUlid;

    public const string PENDING = 'pending';

    public const string READY = 'ready';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id', 'wave_id', 'market_id', 'concept_id', 'storage_key', 'thumb_key', 'original_filename', 'mime_type',
        'declared_bytes', 'size_bytes', 'status', 'expires_at', 'position', 'uploaded_by_user_id',
    ];

    /**
     * PL-2.4: the market (a named group of countries) this row belongs to.
     *
     * @return BelongsTo<WaveMarket, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(WaveMarket::class, 'market_id');
    }

    /** @return BelongsTo<WaveConcept, $this> */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(WaveConcept::class, 'concept_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'declared_bytes' => 'integer',
            'size_bytes' => 'integer',
            'position' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
