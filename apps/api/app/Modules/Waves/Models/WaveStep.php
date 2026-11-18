<?php

declare(strict_types=1);

namespace App\Modules\Waves\Models;

use App\Core\Concerns\HasUlid;
use App\Core\Tenancy\BelongsToAgency;
use App\Modules\Identity\Models\User;
use App\Modules\Waves\Database\Factories\WaveStepFactory;
use App\Modules\Waves\Enums\WaveStepSource;
use App\Modules\Waves\Enums\WaveStepStatus;
use App\Modules\Waves\Enums\WaveStream;
use App\Modules\Waves\Steps\WaveStepKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of a wave's tracker (PL-1.3). Identity is `step_key` (null for a step a person added), never the name.
 * Whether a step is a strategy step is decided by its key, so it cannot drift.
 *
 * @property int $id
 * @property string $ulid
 * @property int $agency_id
 * @property int $wave_id
 * @property WaveStream $stream
 * @property string|null $step_key
 * @property string $name
 * @property int $position
 * @property WaveStepStatus $status
 * @property int|null $owner_user_id
 * @property bool $owner_client
 * @property WaveStepSource $status_source
 * @property Carbon|null $starts_on
 * @property Carbon|null $due_on
 * @property Carbon|null $completed_on
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Wave $wave
 * @property-read User|null $owner
 */
final class WaveStep extends Model
{
    use BelongsToAgency;

    /** @use HasFactory<WaveStepFactory> */
    use HasFactory;

    use HasUlid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'wave_id',
        'stream',
        'step_key',
        'name',
        'position',
        'status',
        'owner_user_id',
        'owner_client',
        'status_source',
        'starts_on',
        'due_on',
        'completed_on',
    ];

    public function isStrategy(): bool
    {
        return WaveStepKey::isStrategy($this->step_key);
    }

    /**
     * @return BelongsTo<Wave, $this>
     */
    public function wave(): BelongsTo
    {
        return $this->belongsTo(Wave::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'stream' => WaveStream::class,
            'status' => WaveStepStatus::class,
            'status_source' => WaveStepSource::class,
            'owner_client' => 'boolean',
            'position' => 'integer',
            'starts_on' => 'date:Y-m-d',
            'due_on' => 'date:Y-m-d',
            'completed_on' => 'date:Y-m-d',
        ];
    }

    protected static function newFactory(): WaveStepFactory
    {
        return WaveStepFactory::new();
    }
}
