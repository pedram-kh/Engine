<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Database\Factories\WaveStrategyNoteFactory;
use App\Modules\Waves\Models\WaveMarket;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An INTERNAL note on a strategy step (PL-2.5, K4): one thread per step per market, one per wave for `usps`. Never
 * serialised for a client or a creator: only the classes of `App\Modules\Strategy\Notes` (and the market-removal guard)
 * may touch this model (an architecture test enforces it). `body` is plain text and may name a person.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property string $step
 * @property int|null $market_id
 * @property int|null $author_user_id
 * @property string $body
 * @property Carbon|null $edited_at
 * @property Carbon $created_at
 * @property-read WaveMarket|null $market
 * @property-read User|null $author
 */
final class WaveStrategyNote extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<WaveStrategyNoteFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'wave_id', 'step', 'market_id', 'author_user_id', 'body', 'edited_at'];

    /** @return BelongsTo<WaveMarket, $this> */
    public function market(): BelongsTo
    {
        return $this->belongsTo(WaveMarket::class, 'market_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    protected static function newFactory(): WaveStrategyNoteFactory
    {
        return WaveStrategyNoteFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }
}
