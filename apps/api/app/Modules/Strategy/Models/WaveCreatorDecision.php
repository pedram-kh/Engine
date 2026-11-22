<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Identity\Models\User;
use App\Modules\Strategy\Database\Factories\WaveCreatorDecisionFactory;
use App\Modules\Strategy\Enums\CreatorDecisionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded decision of a client on a creator (PL-3.1). Append-only; the latest row by id is current. `client_name` is a
 * third party's name typed by an agency user; `note` and `comment` are free text ABOUT THE CREATOR and are scrubbed (null)
 * when the creator is erased (Q6).
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property int $wave_creator_id
 * @property CreatorDecisionStatus $status
 * @property string $client_name
 * @property string|null $note
 * @property string|null $comment
 * @property int|null $recorded_by_user_id
 * @property Carbon $recorded_at
 * @property-read User|null $recordedBy
 */
final class WaveCreatorDecision extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<WaveCreatorDecisionFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'wave_id', 'wave_creator_id', 'status', 'client_name', 'note', 'comment', 'recorded_by_user_id', 'recorded_at'];

    /** @return BelongsTo<WaveCreator, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(WaveCreator::class, 'wave_creator_id');
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
            'status' => CreatorDecisionStatus::class,
            'recorded_at' => 'datetime',
        ];
    }

    protected static function newFactory(): WaveCreatorDecisionFactory
    {
        return WaveCreatorDecisionFactory::new();
    }
}
