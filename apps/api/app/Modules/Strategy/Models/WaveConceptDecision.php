<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Enums\ConceptStatus;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded decision of a client on a concept (PL-2.3). Append-only; the latest row by id is the current decision.
 * `client_name`, `note` and `comment` are typed by agency staff about a client contact (a third party).
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property int $market_id
 * @property-read WaveMarket $market
 * @property int $concept_id
 * @property ConceptStatus $status
 * @property string $client_name
 * @property string|null $note
 * @property string|null $comment
 * @property string $source
 * @property int|null $recorded_by_user_id
 * @property Carbon $recorded_at
 * @property-read User|null $recordedBy
 */
final class WaveConceptDecision extends Model
{
    use BelongsToAgency;
    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id', 'wave_id', 'market_id', 'concept_id', 'status', 'client_name', 'note', 'comment', 'source',
        'recorded_by_user_id', 'recorded_at',
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

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => ConceptStatus::class,
            'recorded_at' => 'datetime',
        ];
    }
}
