<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The strategist's mark that a step is complete (PL-2.5, K1): `usps` per wave (`market_id` null), `personas` and `matrix`
 * per market. A row IS the mark; Reopen deletes it. Concepts is never stored (derived from concept statuses).
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property string $step
 * @property int|null $market_id
 * @property int|null $completed_by_user_id
 * @property Carbon $completed_at
 * @property-read WaveMarket|null $market
 * @property-read User|null $completedBy
 */
final class WaveStrategyCompletion extends Model
{
    use BelongsToAgency;
    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'wave_id', 'step', 'market_id', 'completed_by_user_id', 'completed_at'];

    /** @return BelongsTo<WaveMarket, $this> */
    public function market(): BelongsTo
    {
        return $this->belongsTo(WaveMarket::class, 'market_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
